<?php

namespace App\Http\Controllers\Staff;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePriceRateRequest;
use App\Http\Requests\StorePricingSettingRequest;
use App\Models\PriceRate;
use App\Models\PricingSetting;
use App\Models\Venue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PricingSettingController extends Controller
{
    public function index(Request $request): View
    {
        $rates = PriceRate::query()->orderByDesc('effective_from')->orderByDesc('id')->get();
        $currentRateIds = $rates
            ->where('effective_from', '<=', today())
            ->groupBy(fn (PriceRate $rate): string => implode('|', [
                $rate->kind->value,
                $rate->pricing_category?->value ?? '-',
                $rate->monthly_lesson_count ?? '-',
            ]))
            ->map(fn ($versions): int => $versions->sortByDesc('effective_from')->first()->id)
            ->values();
        $settings = PricingSetting::query()->orderByDesc('effective_from')->orderByDesc('id')->get();
        $currentSettingId = $settings->where('effective_from', '<=', today())->sortByDesc('effective_from')->first()?->id;

        return view('staff.pricing-settings.index', [
            'rates' => $rates,
            'venues' => Venue::query()->orderBy('id')->get(),
            'currentRateIds' => $currentRateIds,
            'settings' => $settings,
            'currentSettingId' => $currentSettingId,
            'canManage' => $request->user()->role === UserRole::Admin,
        ]);
    }

    public function updateVenue(Request $request, Venue $venue): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'lesson_room_type' => ['required', Rule::in(['studio34', 'music_room'])],
        ]);
        $venue->update($data);

        return back()->with('success', '会場名と規約区分を保存しました。今後の予約・振替確定に適用します。');
    }

    public function storeRate(StorePriceRateRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $data = $request->validated();
            $exists = PriceRate::query()
                ->where('kind', $data['kind'])
                ->where('effective_from', $data['effective_from'])
                ->when(isset($data['pricing_category']), fn ($query) => $query->where('pricing_category', $data['pricing_category']), fn ($query) => $query->whereNull('pricing_category'))
                ->when(isset($data['monthly_lesson_count']), fn ($query) => $query->where('monthly_lesson_count', $data['monthly_lesson_count']), fn ($query) => $query->whereNull('monthly_lesson_count'))
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages(['effective_from' => '同じ料金項目・適用開始日の履歴がすでにあります。']);
            }

            PriceRate::create([
                ...$data,
                'created_by_user_id' => $request->user()->id,
            ]);
        }, 3);

        return back()->with('success', '新しい料金履歴を登録しました。');
    }

    public function storeSetting(StorePricingSettingRequest $request): RedirectResponse
    {
        PricingSetting::create([
            ...$request->validated(),
            'admission_campaign_enabled' => $request->boolean('admission_campaign_enabled'),
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', '新しい表示・入会金設定を登録しました。');
    }
}
