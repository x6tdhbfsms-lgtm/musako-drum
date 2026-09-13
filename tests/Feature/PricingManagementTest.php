<?php

namespace Tests\Feature;

use App\Enums\PriceRateKind;
use App\Enums\PricingCategory;
use App\Enums\PricingDisplayMode;
use App\Enums\UserRole;
use App\Models\PriceRate;
use App\Models\PricingSetting;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PricingManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('displayModes')]
    public function test_public_pricing_page_follows_the_configured_display_mode(
        PricingDisplayMode $mode,
        string $expectedText,
        bool $showsTable,
    ): void {
        $this->travelTo('2026-09-13 10:00:00');
        PricingSetting::factory()->create(['effective_from' => '2026-09-01', 'display_mode' => $mode]);

        $response = $this->get(route('pricing'))->assertOk()->assertSee($expectedText);

        $showsTable
            ? $response->assertSee('レギュラーレッスン')
            : $response->assertDontSee('月4回');
    }

    public function test_admin_can_append_a_future_rate_without_changing_the_past_rate(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        PriceRate::factory()->create(['effective_from' => '2026-09-01', 'amount' => 11000]);

        $this->actingAs($admin)
            ->post(route('staff.pricing-settings.rates.store'), [
                'kind' => PriceRateKind::RegularLesson->value,
                'pricing_category' => PricingCategory::Standard->value,
                'monthly_lesson_count' => 2,
                'amount' => 12000,
                'effective_from' => '2026-10-01',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('price_rates', ['amount' => 11000, 'effective_from' => '2026-09-01 00:00:00']);
        $this->assertDatabaseHas('price_rates', [
            'amount' => 12000,
            'effective_from' => '2026-10-01 00:00:00',
            'created_by_user_id' => $admin->id,
        ]);
    }

    public function test_teacher_can_view_pricing_history_but_cannot_change_it(): void
    {
        $teacher = TeacherProfile::factory()->create();
        PriceRate::factory()->create();

        $this->actingAs($teacher->user)
            ->get(route('staff.pricing-settings.index'))
            ->assertOk()
            ->assertSee('先生アカウントでは料金履歴を確認できます')
            ->assertDontSee('料金履歴を追加');

        $this->actingAs($teacher->user)
            ->post(route('staff.pricing-settings.rates.store'), [
                'kind' => PriceRateKind::StudioPerLesson->value,
                'amount' => 1700,
                'effective_from' => '2026-10-01',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('price_rates', ['amount' => 1700]);
    }

    public function test_student_cannot_open_or_change_staff_pricing_settings(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);

        $this->actingAs($student)
            ->get(route('staff.pricing-settings.index'))
            ->assertForbidden();
        $this->actingAs($student)
            ->post(route('staff.pricing-settings.configuration.store'), [
                'effective_from' => '2026-10-01',
                'display_mode' => PricingDisplayMode::CurrentPrices->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('pricing_settings', 0);
    }

    public function test_admin_configuration_rejects_non_http_official_urls(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('staff.pricing-settings.configuration.store'), [
                'effective_from' => '2026-10-01',
                'display_mode' => PricingDisplayMode::CurrentPrices->value,
                'lesson_pricing_url' => 'javascript:alert(1)',
                'studio_pricing_url' => 'ftp://example.com/pricing',
            ])
            ->assertSessionHasErrors(['lesson_pricing_url', 'studio_pricing_url']);

        $this->assertDatabaseCount('pricing_settings', 0);
    }

    public function test_public_pricing_page_escapes_configured_notice_text(): void
    {
        PricingSetting::factory()->create([
            'effective_from' => '2026-01-01',
            'pricing_notice' => '<script>alert("price")</script>',
        ]);

        $this->get(route('pricing'))
            ->assertOk()
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert("price")</script>', false);
    }

    public function test_public_pricing_page_shows_configured_official_links_only_when_present(): void
    {
        PricingSetting::factory()->create([
            'effective_from' => '2026-01-01',
            'lesson_pricing_url' => 'https://example.com/lessons',
            'studio_pricing_url' => 'https://example.com/studio',
        ]);

        $this->get(route('pricing'))
            ->assertOk()
            ->assertSee('https://example.com/lessons', false)
            ->assertSee('https://example.com/studio', false);
    }

    public function test_admin_history_distinguishes_past_current_and_future_rates(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        $admin = User::factory()->admin()->create();
        foreach ([['2026-08-01', 10000], ['2026-09-01', 11000], ['2026-10-01', 12000]] as [$date, $amount]) {
            PriceRate::factory()->create(['effective_from' => $date, 'amount' => $amount]);
        }

        $this->actingAs($admin)
            ->get(route('staff.pricing-settings.index'))
            ->assertOk()
            ->assertSee('¥10,000')
            ->assertSee('¥11,000')
            ->assertSee('¥12,000')
            ->assertSee('過去')
            ->assertSee('現在有効')
            ->assertSee('将来予定');
    }

    /** @return array<string, array{PricingDisplayMode, string, bool}> */
    public static function displayModes(): array
    {
        return [
            'current prices' => [PricingDisplayMode::CurrentPrices, '現在の料金目安', true],
            'example prices' => [PricingDisplayMode::ExamplePrices, '料金例', true],
            'external only' => [PricingDisplayMode::ExternalOnly, '最新料金は公式ページでご確認ください', false],
        ];
    }
}
