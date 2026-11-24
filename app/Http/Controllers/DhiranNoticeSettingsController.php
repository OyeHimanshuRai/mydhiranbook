<?php

namespace App\Http\Controllers;

use App\Models\Dhiran\DhiranSettings;
use App\Services\Dhiran\Notices;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings > Notifications: the daily card on/off, which notices show, and the day windows they use. */
class DhiranNoticeSettingsController extends Controller
{
    public function edit()
    {
        $options = Notices::options(auth()->user()->shop_id);

        return view('dhiran.notice-settings', ['o' => $options, 'toggles' => Notices::toggles($options)]);
    }

    /** The card was opened on screen: it will not open by itself again today. */
    public function seen()
    {
        \App\Models\User::whereKey(auth()->id())->update(['notices_seen_on' => today()->toDateString()]);

        return response()->noContent();
    }

    public function update(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $keys = array_keys(Notices::toggles(Notices::options($shopId)));
        $d = $request->validate([
            'days'         => ['required', 'array', 'size:3'],
            'days.*'       => ['required', 'integer', 'min:1', 'max:90'],
            'forfeit_days' => ['required', 'integer', 'min:1', 'max:90'],
            'show'         => ['nullable', 'array'],
            'show.*'       => [Rule::in($keys)],
        ]);
        $days = array_map('intval', $d['days']);
        if ($days[0] >= $days[1] || $days[1] >= $days[2]) {
            return back()->withInput()->withErrors(['days' => 'Each window must be longer than the one before (for example 3, 7, 30).']);
        }

        DhiranSettings::getForShop($shopId)->update([
            'notices_popup' => $request->boolean('popup'),
            'notices_off' => array_values(array_diff($keys, $d['show'] ?? [])),
            'notices_forfeit_days' => (int) $d['forfeit_days'],
            'notices_interest_days' => $days,
        ]);
        Notices::forget();

        return redirect()->route('dhiran.settings.notices')->with('success', 'Notification settings updated.');
    }
}
