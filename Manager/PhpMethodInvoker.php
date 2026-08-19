<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Manager;

use Aaxis\Bundle\OntologyBundle\Dwl\DwlTransformer;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The "Invoke PHP" flow step: calls one PUBLIC method of an allowed SERVICE with parameters
 * bound BY NAME from the step's DWL object. The locator (built by
 * {@see \Aaxis\Bundle\OntologyBundle\DependencyInjection\Compiler\InvokableServicesPass}, keyed
 * by class name) holds EVERY container service; which classes flows may actually call is the
 * `aaxis_ontology.invoke_php_namespaces` System Configuration setting — namespace prefixes, one
 * per line (default `Aaxis\`) — so admins widen the exposure without a container rebuild.
 *
 * Binding is STRICT — friendlier than letting PHP throw a TypeError mid-call:
 *  - every required parameter must be present (an optional one falls back to its default);
 *  - a value must match the declared type (int stays int — a numeric string fails; int is
 *    accepted where float is expected; null only where the type is nullable; class-typed
 *    parameters cannot be provided from a flow at all and fail with a clear message);
 *  - keys naming no parameter fail (typo protection).
 *
 * The return value is normalized to flow-context data: scalars/null/arrays pass through, objects
 * are converted via json_encode+decode (entities/DTOs with private state may come back empty —
 * expose a serializable shape if a flow needs the data).
 */
class PhpMethodInvoker
{
    public function __construct(
        private readonly ServiceProviderInterface $services,
        private readonly DwlTransformer $dwl,
        private readonly ConfigManager $config,
    ) {
    }

    /** Every callable class under the CONFIGURED namespace prefixes (the class type-ahead). */
    public function invokableClasses(): array
    {
        $prefixes = $this->allowedPrefixes();

        return array_values(array_filter(
            array_keys($this->services->getProvidedServices()),
            static function (string $class) use ($prefixes): bool {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($class, $prefix)) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    public function isInvokable(string $class): bool
    {
        if (!$this->services->has($class)) {
            return false;
        }
        foreach ($this->allowedPrefixes() as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured namespace prefixes: one per line (commas accepted too), leading backslashes
     * trimmed so `\Edge\` and `Edge\` mean the same thing.
     *
     * @return array<int, string>
     */
    private function allowedPrefixes(): array
    {
        $raw = (string) $this->config->get('aaxis_ontology.invoke_php_namespaces');
        $prefixes = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $piece) {
            $piece = ltrim(trim($piece), '\\');
            if ($piece !== '') {
                $prefixes[] = $piece;
            }
        }

        return $prefixes;
    }

    /**
     * @param array<string, mixed> $config  {class, method, params?}
     * @param array<string, mixed> $context
     *
     * @throws \RuntimeException with a user-readable message (the executor prefixes nothing here,
     *                           so every message names the step itself)
     */
    public function invoke(string $stepName, array $config, array $context): mixed
    {
        $class = ltrim(trim((string) ($config['class'] ?? '')), '\\');
        $method = trim((string) ($config['method'] ?? ''));
        if ($class === '' || $method === '') {
            throw new \RuntimeException(sprintf('Step "%s" is not configured.', $stepName));
        }
        if (!$this->isInvokable($class)) {
            throw new \RuntimeException(sprintf(
                'Step "%s": "%s" is not an invokable service (the class must exist as a service AND match the configured namespace prefixes).',
                $stepName,
                $class
            ));
        }

        $args = [];
        $expression = trim((string) ($config['params'] ?? ''));
        if ($expression !== '') {
            try {
                $args = $this->dwl->transform($expression, $context);
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf('Step "%s": %s', $stepName, $e->getMessage()), 0, $e);
            }
            if ($args === null) {
                $args = [];
            }
            if (!\is_array($args) || ($args !== [] && array_is_list($args))) {
                throw new \RuntimeException(sprintf(
                    'Step "%s": the parameters must resolve to an object of named values, got %s.',
                    $stepName,
                    get_debug_type($args)
                ));
            }
        }

        $service = $this->services->get($class);
        try {
            $reflection = new \ReflectionMethod($service, $method);
        } catch (\ReflectionException) {
            throw new \RuntimeException(sprintf('Step "%s": %s has no method "%s".', $stepName, $class, $method));
        }
        if (!$reflection->isPublic() || $reflection->isAbstract() || $reflection->isConstructor() || $reflection->isDestructor()) {
            throw new \RuntimeException(sprintf('Step "%s": %s::%s is not callable.', $stepName, $class, $method));
        }

        $ordered = $this->bindParameters($reflection, $args, $stepName);
        try {
            $result = $reflection->invokeArgs($reflection->isStatic() ? null : $service, $ordered);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'Step "%s": %s::%s failed — %s',
                $stepName,
                $class,
                $method,
                $e->getMessage()
            ), 0, $e);
        }

        return $this->normalize($result);
    }

    /**
     * Positional argument list in declaration order: named values where given, defaults where
     * omitted-and-optional; anything else is an error.
     *
     * @param array<string, mixed> $args
     *
     * @return array<int, mixed>
     */
    private function bindParameters(\ReflectionMethod $method, array $args, string $stepName): array
    {
        $ordered = [];
        $known = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $known[$name] = true;
            if (\array_key_exists($name, $args)) {
                $this->assertType($method, $parameter, $args[$name], $stepName);
                $ordered[] = $args[$name];
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $ordered[] = $parameter->getDefaultValue();
                continue;
            }
            throw new \RuntimeException(sprintf(
                'Step "%s": the required parameter "%s" of %s::%s was not provided.',
                $stepName,
                $name,
                $method->getDeclaringClass()->getName(),
                $method->getName()
            ));
        }

        $unknown = array_keys(array_diff_key($args, $known));
        if ($unknown !== []) {
            throw new \RuntimeException(sprintf(
                'Step "%s": %s::%s has no parameter named "%s".',
                $stepName,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                implode('", "', $unknown)
            ));
        }

        return $ordered;
    }

    private function assertType(\ReflectionMethod $method, \ReflectionParameter $parameter, mixed $value, string $stepName): void
    {
        $type = $parameter->getType();
        if ($type === null || $this->matchesType($type, $value)) {
            return;
        }
        throw new \RuntimeException(sprintf(
            'Step "%s": parameter "%s" of %s::%s expects %s, got %s.',
            $stepName,
            $parameter->getName(),
            $method->getDeclaringClass()->getName(),
            $method->getName(),
            (string) $type,
            get_debug_type($value)
        ));
    }

    private function matchesType(\ReflectionType $type, mixed $value): bool
    {
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($this->matchesType($member, $value)) {
                    return true;
                }
            }

            return false;
        }
        if (!$type instanceof \ReflectionNamedType) {
            // Intersection types are object contracts a flow value can never satisfy.
            return false;
        }
        if ($value === null) {
            return $type->allowsNull();
        }

        return match ($type->getName()) {
            'mixed' => true,
            'int' => \is_int($value),
            // Numeric widening only: an int is a fine float, a string never is.
            'float' => \is_float($value) || \is_int($value),
            'string' => \is_string($value),
            'bool' => \is_bool($value),
            'array', 'iterable' => \is_array($value),
            'null' => false, // $value !== null here
            // Class/interface/object types cannot be built from flow data.
            default => false,
        };
    }

    /** Flow contexts hold plain data — convert object results the same way a JSON round trip would. */
    private function normalize(mixed $result): mixed
    {
        if ($result === null || \is_scalar($result)) {
            return $result;
        }
        if (\is_array($result)) {
            return array_map($this->normalize(...), $result);
        }

        return json_decode((string) json_encode($result), true);
    }
}
