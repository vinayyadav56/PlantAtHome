<?php

namespace Marvel\Integrations;

/**
 * The static definition of one third-party provider: what it is called, which category it belongs
 * to, and which fields it needs — split into non-secret configuration and secret credentials.
 *
 * This is the single declaration that drives BOTH sides: the API validates and stores against it,
 * and the admin renders its form from it. Adding a provider is one entry in ProviderRegistry, not
 * a new migration plus a new form plus a new controller.
 */
class ProviderDefinition
{
    public const CATEGORY_DELIVERY      = 'delivery';
    public const CATEGORY_PAYMENT       = 'payment';
    public const CATEGORY_COMMUNICATION = 'communication';
    public const CATEGORY_AI            = 'ai';
    public const CATEGORY_MAPS          = 'maps';
    public const CATEGORY_STORAGE       = 'storage';
    public const CATEGORY_ANALYTICS     = 'analytics';
    public const CATEGORY_NOTIFICATION  = 'notification';
    public const CATEGORY_TRANSLATION   = 'translation';

    /**
     * @param  string  $slug          Stable identifier, also the sync code for delivery partners.
     * @param  string  $displayName   Human label.
     * @param  string  $category      One of the CATEGORY_* constants.
     * @param  array   $credentialFields  Field definitions for SECRET values.
     * @param  array   $configFields  Field definitions for NON-SECRET values.
     * @param  int     $priority      Lower is preferred within a category.
     * @param  bool    $syncsToShipping  Whether saving pushes credentials to the Go shipping-service.
     * @param  string  $docsUrl       Where an operator finds these credentials.
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $displayName,
        public readonly string $category,
        public readonly array $credentialFields = [],
        public readonly array $configFields = [],
        public readonly int $priority = 100,
        public readonly bool $syncsToShipping = false,
        public readonly string $docsUrl = '',
        public readonly string $blurb = '',
    ) {
    }

    /** Field names of the secret bag. */
    public function credentialNames(): array
    {
        return array_map(static fn (array $f) => $f['name'], $this->credentialFields);
    }

    /** Field names of the non-secret configuration. */
    public function configNames(): array
    {
        return array_map(static fn (array $f) => $f['name'], $this->configFields);
    }

    /**
     * The form schema the admin renders. Secrets are marked so the UI knows to use a password input
     * and the write-only "leave blank to keep" affordance.
     */
    public function fieldSchema(): array
    {
        $map = static function (array $f, bool $secret): array {
            return [
                'name'        => $f['name'],
                'label'       => $f['label'] ?? $f['name'],
                'type'        => $f['type'] ?? ($secret ? 'password' : 'text'),
                'secret'      => $secret,
                'required'    => (bool) ($f['required'] ?? false),
                'placeholder' => $f['placeholder'] ?? '',
                'help'        => $f['help'] ?? '',
                'options'     => $f['options'] ?? null,
            ];
        };

        return [
            'credentials' => array_map(static fn ($f) => $map($f, true), $this->credentialFields),
            'configuration' => array_map(static fn ($f) => $map($f, false), $this->configFields),
        ];
    }

    public function toArray(): array
    {
        return [
            'slug'              => $this->slug,
            'display_name'      => $this->displayName,
            'category'          => $this->category,
            'priority'          => $this->priority,
            'syncs_to_shipping' => $this->syncsToShipping,
            'docs_url'          => $this->docsUrl,
            'blurb'             => $this->blurb,
            'fields'            => $this->fieldSchema(),
        ];
    }
}
