<?php

namespace Tests\Feature;

use App\Models\FacultyRank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FacultyRankTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranks_are_listed_from_lowest_to_highest_salary_grade(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('ranks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ranks.0.name', 'Instructor I')
                ->where('ranks.1.name', 'Instructor II')
                ->where('ranks.2.name', 'Instructor III')
                ->where('ranks.17.name', 'Professor VI')
                ->where('ranks.18.name', 'College/University Professor')
                ->has('ranks', 19));
    }

    public function test_fixed_ranks_cannot_be_added_or_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $rank = FacultyRank::where('name', 'Instructor I')->firstOrFail();

        $this->actingAs($admin)
            ->post('/faculty-ranks', ['name' => 'Custom Rank'])
            ->assertMethodNotAllowed();

        $this->actingAs($admin)
            ->delete("/faculty-ranks/{$rank->id}")
            ->assertNotFound();

        $this->assertDatabaseCount('faculty_ranks', 19);
    }

    public function test_salary_update_endpoint_is_removed_and_retained_rate_is_unchanged(): void
    {
        $rank = FacultyRank::where('name', 'Instructor I')->firstOrFail();

        $original = (float) $rank->rate_amount;
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put('/faculty-ranks/'.$rank->id, ['rate_amount' => 250.50])
            ->assertNotFound();

        $this->assertSame($original, (float) $rank->fresh()->rate_amount);
    }

    public function test_all_fixed_rank_rates_use_the_monthly_reference_divisor(): void
    {
        FacultyRank::whereNotNull('monthly_salary')->each(function (FacultyRank $rank) {
            $this->assertSame(
                round((float) $rank->monthly_salary / 22 / 8, 2),
                (float) $rank->rate_amount,
                "Incorrect hourly rate for {$rank->name}."
            );
        });
    }
}
