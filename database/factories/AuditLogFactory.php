<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'method' => fake()->randomElement(['GET', 'POST', 'PUT', 'DELETE']),
            'endpoint' => '/api/' . fake()->word(),
            'deskripsi' => fake()->sentence(),
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Set created_at (dan updated_at) ke waktu tertentu.
     */
    public function createdAt(\DateTimeInterface $dateTime): static
    {
        return $this->state(fn () => [
            'created_at' => $dateTime,
            'updated_at' => $dateTime,
        ]);
    }

    /**
     * Tandai record sudah di-trash (soft deleted) pada waktu tertentu.
     */
    public function trashedAt(\DateTimeInterface $dateTime): static
    {
        return $this->state(fn () => [
            'deleted_at' => $dateTime,
        ]);
    }
}