<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Runtime;

class Environment
{
    /** @var array<string, Value> */
    private array $bindings = [];
    private ?Environment $parent;

    /** @var array<string, Value>[] */
    private array $scopeStack = [];

    public function __construct(?Environment $parent = null)
    {
        $this->parent = $parent;
    }

    public function define(string $name, Value $value): void
    {
        $this->bindings[$name] = $value;
    }

    public function get(string $name): ?Value
    {
        if (isset($this->bindings[$name])) {
            return $this->bindings[$name];
        }
        // Search through scope stack (most recent first)
        for ($i = count($this->scopeStack) - 1; $i >= 0; $i--) {
            if (isset($this->scopeStack[$i][$name])) {
                return $this->scopeStack[$i][$name];
            }
        }
        return $this->parent?->get($name);
    }

    public function has(string $name): bool
    {
        if (isset($this->bindings[$name])) {
            return true;
        }
        for ($i = count($this->scopeStack) - 1; $i >= 0; $i--) {
            if (isset($this->scopeStack[$i][$name])) {
                return true;
            }
        }
        return $this->parent?->has($name) ?? false;
    }

    public function child(): self
    {
        return new self($this);
    }

    /**
     * Push current bindings onto a stack and start a fresh scope.
     * get() will search through the stack, so parent bindings remain visible.
     */
    public function pushScope(): void
    {
        $this->scopeStack[] = $this->bindings;
        $this->bindings = [];
    }

    public function popScope(): void
    {
        $this->bindings = array_pop($this->scopeStack);
    }
}
