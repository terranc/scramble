<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor;

use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\SchemaClassDocReflector;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;
use PhpParser\NodeFinder;
use ReflectionClass;
use Spatie\LaravelData\Contracts\BaseData;

class FormRequestRulesExtractor implements RulesExtractor
{
    use GeneratesParametersFromRules;

    public function __construct(private ?FunctionLike $handler, private TypeTransformer $typeTransformer) {}

    public function shouldHandle(): bool
    {
        if (! $this->handler) {
            return false;
        }

        if (! collect($this->handler->getParams())->contains($this->findCustomRequestParam(...))) {
            return false;
        }

    public function node(Route $route)
    {
        $requestClassName = $this->getFormRequestClassName();

        if ($requestClassName === 'Lorisleiva\Actions\ActionRequest') {

            $actionClassName = explode('@', $route->action['controller'])[0];
            $result = resolve(FileParser::class)->parse((new ReflectionClass($actionClassName))->getFileName());
            $rulesMethodNode = $result->findMethod("$actionClassName@rules");
        } else {
            $result = resolve(FileParser::class)->parse((new ReflectionClass($requestClassName))->getFileName());
            $rulesMethodNode = $result->findMethod("$requestClassName@rules");
        }
        if (! $rulesMethodNode) {
            return null;
        }

        $classReflector = Infer\Reflector\ClassReflector::make($requestClassName);

        $phpDocReflector = SchemaClassDocReflector::createFromDocString($classReflector->getReflection()->getDocComment() ?: '');

        $schemaName = ($phpDocReflector->getTagValue('@ignoreSchema')->value ?? null) !== null
            ? null
            : $phpDocReflector->getSchemaName($requestClassName);

        return new ParametersExtractionResult(
            parameters: $this->makeParameters(
                node: (new NodeFinder)->find(
                    Arr::wrap($classReflector->getMethod('rules')->getAstNode()->stmts),
                    fn (Node $node) => $node instanceof Node\Expr\ArrayItem
                        && $node->key instanceof Node\Scalar\String_
                        && $node->getAttribute('parsedPhpDoc'),
                ),
                rules: $this->rules($routeInfo->route),
                typeTransformer: $this->typeTransformer,
            ),
            schemaName: $schemaName,
            description: $phpDocReflector->getDescription(),
        );
    }

    protected function rules(Route $route)
    {
        $requestClassName = $this->getFormRequestClassName();

        if ($requestClassName === 'Lorisleiva\Actions\ActionRequest') {
            $actionClassName = explode('@', $route->action['controller'])[0];
            $action = (new $actionClassName);
            return $action->rules();
        } else {
            /** @var Request $request */
            $request = (new $requestClassName);

            $request->setMethod($route->methods()[0]);

            return $request->rules();
        }
    }

    private function findCustomRequestParam(Param $param)
    {
        if (! $param->type || ! method_exists($param->type, '__toString')) {
            return false;
        }

        $className = (string) $param->type;

        return method_exists($className, 'rules');
    }

    private function getFormRequestClassName()
    {
        $requestParam = collect($this->handler->getParams())->first($this->findCustomRequestParam(...));

        $requestClassName = (string) $requestParam->type;

        $reflectionClass = new ReflectionClass($requestClassName);

        // If the classname is actually an interface, it might be bound to the container.
        if (! $reflectionClass->isInstantiable() && app()->bound($requestClassName)) {
            $classInstance = app()->getBindings()[$requestClassName]['concrete'](app());
            $requestClassName = $classInstance::class;
        }

        return $requestClassName;
    }
}
