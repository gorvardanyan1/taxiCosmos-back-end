<?php

namespace Database\Factories;

use App\Enums\AppClient;
use App\Enums\SupportCategory;
use App\Enums\SupportPriority;
use App\Enums\SupportStatus;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportTicket> */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requester_id' => User::factory()->rider(),
            'requester_type' => AppClient::Rider,
            'subject' => fake()->sentence(4),
            'category' => SupportCategory::Other,
            'priority' => SupportPriority::Normal,
            'status' => SupportStatus::Open,
            'last_message_at' => now(),
        ];
    }

    public function status(SupportStatus $status): static
    {
        return $this->state(fn () => ['status' => $status] + ($status->isFinished() ? ['resolved_at' => now(), 'resolution' => 'Fixed.'] : []));
    }

    public function priority(SupportPriority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    public function category(SupportCategory $category): static
    {
        return $this->state(fn () => ['category' => $category]);
    }

    public function forDriver(): static
    {
        return $this->state(fn () => ['requester_id' => User::factory()->driver(), 'requester_type' => AppClient::Driver]);
    }
}
