<?php

declare(strict_types=1);

namespace ZeroForLaravel\Formbraid;

use Formbraid\Laravel\Contracts\OptionSource;
use Formbraid\Laravel\OptionInput;
use InvalidArgumentException;
use stdClass;
use ZeroForLaravel\Query\ZeroQuery;

/** A build-time option declaration. Formbraid never receives its query as JSON. */
final readonly class ZeroOptions implements OptionSource
{
    /** @param array<string, string> $authorize Context model name to read-policy ability. */
    private function __construct(
        public ZeroQuery $listing,
        public string $value,
        public string $label,
        public ?ZeroQuery $resolve,
        public array $authorize,
    ) {}

    /** @param array<string, string> $authorize */
    public static function query(ZeroQuery $query, string $value, string $label, ?ZeroQuery $resolve = null, array $authorize = []): self
    {
        foreach ([$value, $label] as $column) {
            if (! isset($query->model->columns[$column])) {
                throw new InvalidArgumentException('Unknown option column '.$column.'.');
            }
        }

        if (! in_array($query->model->columns[$value]['type'], ['string', 'number'], true) || $query->model->columns[$label]['type'] !== 'string') {
            throw new InvalidArgumentException('Options require a string/number value and a string label.');
        }

        if ($resolve instanceof ZeroQuery && $resolve->model->class !== $query->model->class) {
            throw new InvalidArgumentException('Option listing and resolution must return the same model.');
        }

        return new self(clone $query, $value, $label, $resolve instanceof ZeroQuery ? clone $resolve : null, $authorize);
    }

    public static function contains(OptionInput $input): OptionPattern
    {
        return new OptionPattern($input);
    }

    public function inputs(): array
    {
        return self::references([$this->listing->constructionAst(), array_map(OptionInput::context(...), array_keys($this->authorize))]);
    }

    public function resolveInputs(): array
    {
        return $this->resolve instanceof ZeroQuery ? self::references([$this->resolve->constructionAst(), array_map(OptionInput::context(...), array_keys($this->authorize))]) : [];
    }

    /** @return list<OptionInput> */
    private static function references(mixed $node): array
    {
        if ($node instanceof OptionPattern) {
            return [$node->input];
        }

        if ($node instanceof OptionInput) {
            return [$node];
        }

        $references = [];
        if (is_array($node) || $node instanceof stdClass) {
            foreach ((array) $node as $child) {
                foreach (self::references($child) as $input) {
                    $references[json_encode($input->toArray(), JSON_THROW_ON_ERROR)] = $input;
                }
            }
        }

        return array_values($references);
    }
}
