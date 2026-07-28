<?php

namespace Tests\Feature\Integrations;

use Marvel\Integrations\ProviderDefinition;
use Marvel\Integrations\ProviderRegistry;
use Tests\TestCase;

/**
 * Guards on the provider catalogue itself.
 *
 * Field NAMES here are a cross-repo contract: the same snake_case string is the admin form input,
 * the key in the encrypted bag, and — for delivery partners — the key the Go shipping-service reads
 * out of the synced credentials. A rename that looks cosmetic silently breaks partner auth in a
 * different repository, so the important ones are pinned.
 */
final class IntegrationRegistryTest extends TestCase
{
    public function test_every_definition_is_internally_consistent(): void
    {
        foreach (ProviderRegistry::all() as $slug => $def) {
            $this->assertSame($slug, $def->slug, 'registry key must equal the slug');
            $this->assertNotSame('', trim($def->displayName), "{$slug} needs a display name");
            $this->assertContains($def->category, ProviderRegistry::categories(), "{$slug} has an unknown category");

            $names = array_merge($def->credentialNames(), $def->configNames());
            $this->assertSame(
                count($names),
                count(array_unique($names)),
                "{$slug} declares the same field in both credentials and configuration — it would be stored twice, once unencrypted"
            );

            foreach ($names as $name) {
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $name, "{$slug}.{$name} must be snake_case");
            }
        }
    }

    /**
     * The delivery partners' credential field names are read by the Go shipping-service. If this
     * test fails, partner authentication in that service breaks — check both repositories together.
     */
    public function test_delivery_partner_field_names_match_the_go_service(): void
    {
        $expected = [
            'porter'     => ['api_key', 'webhook_token'],
            'borzo'      => ['token', 'callback_token'],
            'shiprocket' => ['email', 'password', 'api_token', 'webhook_token'],
        ];

        foreach ($expected as $slug => $fields) {
            $def = ProviderRegistry::find($slug);
            $this->assertNotNull($def, "{$slug} must exist in the registry");
            $this->assertSame($fields, $def->credentialNames(), "{$slug} credential field names are a cross-repo contract");
            $this->assertTrue($def->syncsToShipping, "{$slug} must sync to the shipping service");
        }
    }

    /**
     * A public key must never sit in the encrypted credential bag, and a secret must never sit in
     * the plaintext configuration column. Getting this backwards is the exact failure the
     * two-column schema exists to prevent.
     */
    public function test_public_identifiers_are_configuration_and_secrets_are_credentials(): void
    {
        $publicInConfig = [
            'razorpay'         => 'key_id',
            'stripe'           => 'publishable_key',
            'google_analytics' => 'measurement_id',
            'meta_pixel'       => 'pixel_id',
        ];
        foreach ($publicInConfig as $slug => $field) {
            $def = ProviderRegistry::find($slug);
            $this->assertContains($field, $def->configNames(), "{$slug}.{$field} is public and belongs in configuration");
            $this->assertNotContains($field, $def->credentialNames());
        }

        // Anything that looks like a secret must be in the encrypted bag.
        foreach (ProviderRegistry::all() as $slug => $def) {
            foreach ($def->configNames() as $name) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(^|_)(secret|password|private_key)(_|$)/',
                    $name,
                    "{$slug}.{$name} looks like a secret but is declared as non-encrypted configuration"
                );
            }
        }
    }

    public function test_porter_cod_is_declared_but_defaults_off(): void
    {
        $def = ProviderRegistry::find('porter');
        $this->assertContains('cod_supported', $def->configNames());

        // It must NOT be a required credential — Porter is usable without COD, and requiring it
        // would make the provider read as unconfigured.
        $this->assertNotContains('cod_supported', $def->credentialNames());
    }

    public function test_shipping_service_is_highest_priority_in_delivery(): void
    {
        $delivery = ProviderRegistry::byCategory(ProviderDefinition::CATEGORY_DELIVERY);
        $this->assertArrayHasKey('shipping_service', $delivery);

        // Porter before Borzo before Shiprocket, matching Descriptor.Priority in the Go service.
        $this->assertLessThan(
            ProviderRegistry::find('borzo')->priority,
            ProviderRegistry::find('porter')->priority,
            'Porter must outrank Borzo, matching the Go registry'
        );
        $this->assertLessThan(
            ProviderRegistry::find('shiprocket')->priority,
            ProviderRegistry::find('borzo')->priority
        );
    }

    public function test_field_schema_marks_secrets_for_the_admin_form(): void
    {
        $schema = ProviderRegistry::find('porter')->fieldSchema();

        $this->assertNotEmpty($schema['credentials']);
        foreach ($schema['credentials'] as $field) {
            $this->assertTrue($field['secret'], 'credential fields must be flagged secret so the UI masks them');
        }
        foreach ($schema['configuration'] as $field) {
            $this->assertFalse($field['secret']);
        }
    }
}
