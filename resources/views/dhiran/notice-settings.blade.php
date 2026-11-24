<x-dhiran-layout title="Notifications" settings footer>
    <x-dhiran.page-header>
        <x-dhiran.settings-nav active="notices" as="title" />
    </x-dhiran.page-header>

    <turbo-frame id="settings-pane" data-turbo-action="advance">
    <div class="content-inner">
        <x-dhiran.settings-nav active="notices" switcher />
        <x-settings.live />
        <p class="st-lead">What the bell tells you, and the card that opens once a day on your first visit. Each line links to the loans it is about.</p>
        <form method="POST" action="{{ route('dhiran.settings.notices.update') }}" class="st-page" data-turbo-frame="_top"
              x-data="stSaveForm({{ session()->hasOldInput() ? 'true' : 'false' }})" @input="dirty = true" @change="dirty = true" @submit="submit($event)"
              @beforeunload.window="if (dirty && !saving) $event.preventDefault()">
            @csrf
            @method('PATCH')
            <div class="st-stack" data-settings-board>
                <x-settings.panel title="Daily card" desc="A card in the middle of the screen, once a day.">
                    <x-dhiran.switch id="nt-popup" name="popup" value="1" label="Open the card on my first visit each day" :checked="(bool) old('popup', $o['popup'])"
                        hint="Off: nothing opens by itself. The bell on every page still shows everything." />
                </x-settings.panel>

                <x-settings.panel title="Days" desc="How far ahead to look.">
                    <div class="st-grid">
                        @foreach([0 => 'First window', 1 => 'Second window', 2 => 'Third window'] as $i => $label)
                            <x-settings.field :name="'days['.$i.']'" :id="'nt-d'.$i" :label="'Interest due: '.$label" type="number" inputmode="numeric" min="1" max="90" step="1" req required suffix="days"
                                :value="old('days.'.$i, $o['days'][$i])" :hint="$i === 0 ? 'Shown as: due within this many days.' : 'Counted after the window before it.'" />
                        @endforeach
                        <x-settings.field name="forfeit_days" id="nt-forfeit" label="Forfeiture warning" type="number" inputmode="numeric" min="1" max="90" step="1" req required suffix="days"
                            :value="old('forfeit_days', $o['forfeit_days'])" hint="Warn this many days before a loan reaches the forfeiture notice stage." />
                    </div>
                    @error('days')<p class="st-error" role="alert">{{ $message }}</p>@enderror
                </x-settings.panel>

                <x-settings.panel title="What to tell me" desc="Switch off a line you do not need.">
                    @foreach($toggles as $key => $label)
                        <x-dhiran.switch :id="'nt-'.$key" name="show[]" :value="$key" :label="'Loans '.lcfirst($label)" :checked="! in_array($key, $o['off'], true)" />
                    @endforeach
                </x-settings.panel>
            </div>
            <x-settings.savebar label="Save settings" :cancel-href="route('dhiran.loans')" />
        </form>
    </div>
    </turbo-frame>
</x-dhiran-layout>
