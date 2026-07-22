<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application;

use App\Modules\Marketing\Application\DTO\ScheduleConfig;
use App\Modules\Marketing\Domain\CampaignStatus;
use App\Modules\Marketing\Domain\Channel;
use App\Modules\Marketing\Domain\Events\CampaignLaunched;
use App\Modules\Marketing\Domain\RunStatus;
use App\Modules\Marketing\Domain\ScheduleType;
use App\Modules\Marketing\Infrastructure\Models\Audience;
use App\Modules\Marketing\Infrastructure\Models\Campaign;
use App\Modules\Marketing\Infrastructure\Models\CampaignRun;
use App\Modules\Marketing\Infrastructure\Models\CampaignTemplate;
use App\Modules\Marketing\Infrastructure\Models\Template;
use App\Modules\Marketing\Jobs\GenerateCampaignBatchesJob;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Campaign lifecycle. The HTTP layer only ever builds/schedules a campaign and
 * asks to launch — launching creates a CampaignRun and hands the heavy work
 * (materialize → batch → send) to the queue. Nothing is ever sent in-request.
 */
final class CampaignService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AudienceService $audiences,
        private readonly EventPublisher $events,
    ) {
    }

    public function create(array $data, ?string $actorUuid): Campaign
    {
        return $this->db->transaction(function () use ($data, $actorUuid) {
            $schedule = ScheduleConfig::fromArray($data, (string) config('marketing.campaign.default_timezone', 'Asia/Kolkata'));

            $campaign = Campaign::create([
                'name'                => $data['name'],
                'description'         => $data['description'] ?? null,
                'audience_id'         => $this->resolveAudienceId($data['audience_uuid'] ?? null),
                'channels'            => $this->normalizeChannels($data['channels'] ?? []),
                'status'              => CampaignStatus::DRAFT,
                'schedule_type'       => $schedule->type,
                'scheduled_at'        => $schedule->scheduledAt,
                'cron_expression'     => $schedule->cron,
                'timezone'            => $schedule->timezone,
                'batch_size'          => (int) ($data['batch_size'] ?? config('marketing.campaign.default_batch_size', 1000)),
                'throttle_per_minute' => isset($data['throttle_per_minute']) ? (int) $data['throttle_per_minute'] : (int) config('marketing.campaign.default_throttle_per_min', 0),
                'send_delay_seconds'  => (int) ($data['send_delay_seconds'] ?? 0),
                'priority'            => (int) ($data['priority'] ?? 0),
                'max_retries'         => (int) ($data['max_retries'] ?? config('marketing.campaign.default_max_retries', 3)),
                'created_by_uuid'     => $actorUuid,
            ]);

            $this->syncTemplates($campaign, $data['templates'] ?? []);
            $this->applySchedule($campaign, $schedule);

            return $campaign->fresh(['templates']);
        });
    }

    public function update(Campaign $campaign, array $data, ?string $actorUuid = null): Campaign
    {
        return $this->db->transaction(function () use ($campaign, $data) {
            if (array_key_exists('audience_uuid', $data)) {
                $campaign->audience_id = $this->resolveAudienceId($data['audience_uuid']);
                $campaign->audience_version_id = null; // re-pin at next launch
            }
            if (array_key_exists('channels', $data)) {
                $campaign->channels = $this->normalizeChannels($data['channels']);
            }
            foreach (['name', 'description', 'batch_size', 'throttle_per_minute', 'send_delay_seconds', 'priority', 'max_retries'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null) {
                    $campaign->{$field} = $data[$field];
                }
            }

            $schedule = ScheduleConfig::fromArray(array_merge([
                'schedule_type'   => $campaign->schedule_type,
                'scheduled_at'    => $campaign->scheduled_at,
                'cron_expression' => $campaign->cron_expression,
                'timezone'        => $campaign->timezone,
            ], $data), (string) config('marketing.campaign.default_timezone', 'Asia/Kolkata'));

            $campaign->schedule_type = $schedule->type;
            $campaign->scheduled_at = $schedule->scheduledAt;
            $campaign->cron_expression = $schedule->cron;
            $campaign->timezone = $schedule->timezone;
            $campaign->save();

            if (array_key_exists('templates', $data)) {
                $this->syncTemplates($campaign, $data['templates']);
            }
            $this->applySchedule($campaign, $schedule);

            return $campaign->fresh(['templates']);
        });
    }

    /**
     * Create a run and enqueue it. Validates the campaign is launchable (has an
     * audience with a snapshot + a template for every channel), pins the audience
     * version so the recipient set is frozen, then dispatches the materialize job.
     */
    public function launch(Campaign $campaign, ?string $actorUuid, string $trigger = 'manual'): CampaignRun
    {
        $this->assertLaunchable($campaign);

        $run = $this->db->transaction(function () use ($campaign, $actorUuid, $trigger) {
            $audience = Audience::find($campaign->audience_id);
            $version = $this->audiences->resolveVersion(
                $audience,
                $campaign->audience_version_id
                    ? optional($campaign->audienceVersion)->uuid
                    : null,
            );

            // Pin the version on the campaign so re-launches use the same set
            // until the audience is explicitly refreshed + re-pinned.
            $campaign->audience_version_id = $version->id;
            $campaign->status = CampaignStatus::RUNNING;
            $campaign->last_run_at = Carbon::now();
            $campaign->next_run_at = $this->advanceSchedule($campaign);
            $campaign->save();

            $run = CampaignRun::create([
                'campaign_id'         => $campaign->id,
                'audience_version_id' => $version->id,
                'status'              => RunStatus::PENDING,
                'trigger'             => $trigger,
                'total_recipients'    => 0,
                'total_messages'      => 0,
                'created_by_uuid'     => $actorUuid,
            ]);

            $this->events->publish(new CampaignLaunched($campaign->uuid, $run->uuid, $trigger, $actorUuid));

            return $run;
        });

        // Dispatch AFTER commit so the worker never races an uncommitted run.
        GenerateCampaignBatchesJob::dispatch($run->id)
            ->onQueue((string) config('marketing.queue', 'marketing'));

        return $run;
    }

    /** Pause a scheduled/running campaign so the scheduler skips it. */
    public function pause(Campaign $campaign): Campaign
    {
        $campaign->update(['status' => CampaignStatus::PAUSED, 'next_run_at' => null]);

        return $campaign->fresh();
    }

    /** Resume: recompute the next fire time from the schedule. */
    public function resume(Campaign $campaign): Campaign
    {
        $schedule = $this->scheduleOf($campaign);
        $next = $schedule->nextRunAt();
        $campaign->update([
            'status'      => $next ? CampaignStatus::SCHEDULED : CampaignStatus::DRAFT,
            'next_run_at' => ScheduleType::isRecurring($campaign->schedule_type) || $campaign->schedule_type === ScheduleType::ONCE ? $next : null,
        ]);

        return $campaign->fresh();
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function assertLaunchable(Campaign $campaign): void
    {
        if (! $campaign->audience_id) {
            throw DomainActionException::unprocessable('This campaign has no audience.', 'CAMPAIGN_NO_AUDIENCE');
        }
        $channels = (array) $campaign->channels;
        if ($channels === []) {
            throw DomainActionException::unprocessable('This campaign has no channels.', 'CAMPAIGN_NO_CHANNELS');
        }
        $haveTemplates = $campaign->templates()->pluck('channel')->all();
        $missing = array_values(array_diff($channels, $haveTemplates));
        if ($missing !== []) {
            throw DomainActionException::unprocessable(
                'Missing a template for channel(s): '.implode(', ', $missing).'.',
                'CAMPAIGN_MISSING_TEMPLATE',
            );
        }
    }

    private function resolveAudienceId(?string $audienceUuid): ?int
    {
        if (! $audienceUuid) {
            return null;
        }
        $audience = Audience::where('uuid', $audienceUuid)->first();
        if (! $audience) {
            throw DomainActionException::unprocessable('That audience does not exist.', 'AUDIENCE_NOT_FOUND', 'audience_uuid');
        }

        return $audience->id;
    }

    /** @param array<int,string> $channels @return array<int,string> */
    private function normalizeChannels(array $channels): array
    {
        $valid = array_values(array_unique(array_filter($channels, fn ($c) => Channel::isValid((string) $c))));
        if ($channels !== [] && $valid === []) {
            throw DomainActionException::unprocessable('No valid channels supplied.', 'CAMPAIGN_INVALID_CHANNELS', 'channels');
        }

        return $valid;
    }

    /**
     * Replace the campaign's channel→template bindings from a
     * [{channel, template_uuid}] list; ignores channels not on the campaign.
     *
     * @param array<int, array{channel:string, template_uuid:string}> $templates
     */
    private function syncTemplates(Campaign $campaign, array $templates): void
    {
        $campaign->templates()->delete();

        // One template per channel (the table enforces unique(campaign_id,channel));
        // dedupe last-wins so a duplicated channel is a clean overwrite, not a 500.
        $byChannel = [];
        foreach ($templates as $binding) {
            $channel = (string) ($binding['channel'] ?? '');
            if (Channel::isValid($channel)) {
                $byChannel[$channel] = $binding;
            }
        }

        foreach ($byChannel as $binding) {
            $channel = (string) ($binding['channel'] ?? '');
            $templateUuid = (string) ($binding['template_uuid'] ?? '');
            if (! Channel::isValid($channel) || $templateUuid === '') {
                continue;
            }
            $template = Template::where('uuid', $templateUuid)->first();
            if (! $template) {
                throw DomainActionException::unprocessable("Template {$templateUuid} not found.", 'TEMPLATE_NOT_FOUND', 'templates');
            }
            if ($template->channel !== $channel) {
                throw DomainActionException::unprocessable(
                    "Template {$template->name} is a {$template->channel} template, not {$channel}.",
                    'TEMPLATE_CHANNEL_MISMATCH',
                    'templates',
                );
            }
            CampaignTemplate::create([
                'campaign_id' => $campaign->id,
                'template_id' => $template->id,
                'channel'     => $channel,
            ]);
        }
    }

    private function scheduleOf(Campaign $campaign): ScheduleConfig
    {
        return new ScheduleConfig(
            $campaign->schedule_type,
            $campaign->scheduled_at,
            $campaign->cron_expression,
            $campaign->timezone,
        );
    }

    /** Set status + next_run_at when a campaign is saved with a schedule. */
    private function applySchedule(Campaign $campaign, ScheduleConfig $schedule): void
    {
        if ($schedule->type === ScheduleType::NOW) {
            // "Run now" campaigns are launched explicitly via the send endpoint.
            return;
        }
        $next = $schedule->nextRunAt();
        if ($next) {
            $campaign->update([
                'status'      => CampaignStatus::SCHEDULED,
                'next_run_at' => $next,
            ]);
        }
    }

    /** After a launch, compute when a recurring campaign should fire again. */
    private function advanceSchedule(Campaign $campaign): ?Carbon
    {
        if (! ScheduleType::isRecurring($campaign->schedule_type)) {
            return null;
        }

        return $this->scheduleOf($campaign)->nextRunAt(Carbon::now());
    }
}
