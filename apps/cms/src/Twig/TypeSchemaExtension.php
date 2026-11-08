<?php

namespace App\Twig;

use App\Service\AcmsManifestPropertyBridge;
use App\Service\TypeSchemaRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TypeSchemaExtension extends AbstractExtension
{
    private TypeSchemaRegistry $typeSchemaRegistry;

    private AcmsManifestPropertyBridge $acmsManifestPropertyBridge;

    public function __construct(
        TypeSchemaRegistry $typeSchemaRegistry,
        AcmsManifestPropertyBridge $acmsManifestPropertyBridge
    ) {
        $this->typeSchemaRegistry = $typeSchemaRegistry;
        $this->acmsManifestPropertyBridge = $acmsManifestPropertyBridge;
    }

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('acms_public_type_id', [$this, 'formatPublicTypeId']),
            new TwigFilter('acms_frontmatter_key', [$this, 'formatFrontmatterKey']),
        ];
    }

    public function formatPublicTypeId(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return $this->typeSchemaRegistry->publicTypeId($value);
    }

    public function formatFrontmatterKey(mixed $key): string
    {
        if (!is_string($key)) {
            return '';
        }

        return $this->acmsManifestPropertyBridge->acmsKeyFromAny($key);
    }
}
