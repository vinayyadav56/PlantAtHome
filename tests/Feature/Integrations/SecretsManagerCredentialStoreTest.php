<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\SecretsManager\Exception\SecretsManagerException;
use Aws\SecretsManager\SecretsManagerClient;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\Store\SecretsManagerCredentialStore;
use Tests\TestCase;

/**
 * The Secrets Manager driver, against a mocked SDK.
 *
 * The client is built with `credentials => false` and a MockHandler so nothing here ever waits
 * on the instance-metadata endpoint or reaches AWS. What is pinned: the secret name, lazy
 * creation on the first save, the version id landing on the row, batch reads tolerating a
 * missing secret, and reads that never throw.
 */
final class SecretsManagerCredentialStoreTest extends TestCase
{
    private MockHandler $mock;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.secrets_manager.prefix' => 'plantathome']);
        $this->mock = new MockHandler();
    }

    private function store(): SecretsManagerCredentialStore
    {
        return new SecretsManagerCredentialStore(new SecretsManagerClient([
            'region'      => 'ap-south-1',
            'version'     => 'latest',
            'credentials' => false,
            'handler'     => $this->mock,
        ]));
    }

    private function row(string $slug = 'razorpay', string $environment = 'production'): IntegrationProvider
    {
        return new IntegrationProvider(['provider_slug' => $slug, 'environment' => $environment]);
    }

    private function notFound(): callable
    {
        return static fn (CommandInterface $cmd) => new SecretsManagerException('missing', $cmd, ['code' => 'ResourceNotFoundException']);
    }

    public function test_the_secret_is_named_per_environment_and_service(): void
    {
        $this->assertSame('plantathome/production/razorpay', $this->store()->secretName($this->row()));
        $this->assertSame('plantathome/staging/msg91', $this->store()->secretName($this->row('msg91', 'staging')));
    }

    public function test_a_first_save_creates_the_secret_and_stages_the_version_on_the_row(): void
    {
        $this->mock->append($this->notFound());                       // PutSecretValue → no such secret
        $this->mock->append(new Result(['VersionId' => 'v-created']));  // CreateSecret

        $row = $this->row();
        $version = $this->store()->put($row, ['key_secret' => 'rzp-SECRET'], 42);

        $this->assertSame('v-created', $version);
        $this->assertSame('plantathome/production/razorpay', $row->secret_name);
        $this->assertSame('v-created', $row->secret_version_id);
        $this->assertSame(42, $row->last_updated_by);
        $this->assertNull($row->credentials, 'the bag must never touch the row');

        $create = $this->mock->getLastCommand();
        $this->assertSame('CreateSecret', $create->getName());
        $this->assertSame('plantathome/production/razorpay', $create['Name']);
        $this->assertSame('{"key_secret":"rzp-SECRET"}', $create['SecretString']);
        $this->assertContains(['Key' => 'Environment', 'Value' => 'production'], $create['Tags']);
    }

    public function test_a_later_save_puts_a_new_version(): void
    {
        $this->mock->append(new Result(['VersionId' => 'v-2']));

        $row = $this->row();
        $this->store()->put($row, ['key_secret' => 'rotated']);

        $this->assertSame('PutSecretValue', $this->mock->getLastCommand()->getName());
        $this->assertSame('v-2', $row->secret_version_id);
    }

    public function test_a_read_returns_the_bag_as_strings(): void
    {
        $this->mock->append(new Result(['SecretString' => '{"key_secret":"abc","webhook_secret":123}']));

        $this->assertSame(['key_secret' => 'abc', 'webhook_secret' => '123'], $this->store()->get($this->row()));
    }

    public function test_a_missing_secret_reads_as_nothing_stored(): void
    {
        $this->mock->append($this->notFound());

        $this->assertNull($this->store()->get($this->row()));
    }

    public function test_a_read_failure_never_throws(): void
    {
        $this->mock->append(static fn (CommandInterface $cmd) => new SecretsManagerException('down', $cmd, ['code' => 'InternalServiceError']));

        $this->assertNull($this->store()->get($this->row()), 'callers fall through to env; a store outage is not a 500');
    }

    public function test_a_write_failure_does_throw(): void
    {
        $this->mock->append(static fn (CommandInterface $cmd) => new SecretsManagerException('denied', $cmd, ['code' => 'AccessDeniedException']));

        $this->expectException(SecretsManagerException::class);
        $this->store()->put($this->row(), ['key_secret' => 'x']);
    }

    public function test_batch_read_maps_secrets_back_to_slugs_and_tolerates_a_missing_one(): void
    {
        $this->mock->append(new Result([
            'SecretValues' => [
                ['Name' => 'plantathome/production/razorpay', 'SecretString' => '{"key_secret":"a"}'],
                ['Name' => 'plantathome/production/msg91',    'SecretString' => '{"auth_key":"b"}'],
            ],
            'Errors' => [
                ['SecretId' => 'plantathome/production/whatsapp', 'ErrorCode' => 'ResourceNotFoundException'],
            ],
        ]));

        $bags = $this->store()->getMany([$this->row('razorpay'), $this->row('msg91'), $this->row('whatsapp')]);

        $this->assertSame(['razorpay' => ['key_secret' => 'a'], 'msg91' => ['auth_key' => 'b']], $bags);
        $this->assertSame('BatchGetSecretValue', $this->mock->getLastCommand()->getName());
    }

    public function test_batch_read_never_asks_for_another_environments_secret(): void
    {
        $this->mock->append(new Result(['SecretValues' => [], 'Errors' => []]));

        $this->store()->getMany([$this->row('razorpay', 'staging')]);

        $this->assertSame(['plantathome/staging/razorpay'], $this->mock->getLastCommand()['SecretIdList']);
    }

    public function test_delete_schedules_recovery_rather_than_destroying(): void
    {
        $this->mock->append(new Result([]));

        $row = $this->row();
        $row->secret_name = 'plantathome/production/razorpay';
        $this->store()->delete($row);

        $cmd = $this->mock->getLastCommand();
        $this->assertSame('DeleteSecret', $cmd->getName());
        $this->assertSame(7, $cmd['RecoveryWindowInDays']);
        $this->assertNull($row->secret_name);
    }
}
