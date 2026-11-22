<x-dhiran-layout title="Upload Old Loans">
    @vite('resources/css/dhiran-forms.css')
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Upload Old Loans</h1>
            <p class="text-sm text-gray-500 mt-1">Bring in many loans from your paper book at once</p>
        </div>
        <div class="page-actions">
            <x-dhiran.btn-collapse icon="arrow-left" label="Old Loans" :href="route('dhiran.old-loans.index')" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner">
        <div class="fm-page fm-page--narrow">
            <ol class="fm-flow" aria-label="The three steps">
                <li aria-current="step"><span class="fm-num" aria-hidden="true">1</span><span class="fm-flow-name">Download template</span></li>
                <li><span class="fm-num" aria-hidden="true">2</span><span class="fm-flow-name">Upload file</span></li>
                <li><span class="fm-num" aria-hidden="true">3</span><span class="fm-flow-name">Review</span></li>
            </ol>

            <section class="fm-card" aria-labelledby="up-h-1">
                <header class="fm-card-head">
                    <span class="fm-num" aria-hidden="true">1</span>
                    <div>
                        <h2 class="fm-title" id="up-h-1">Fill in the template</h2>
                        <p class="fm-desc">One row per pledged item. Rows with the same ticket number are one loan; write the loan and customer details on its first row. Dates like 15-01-2026. Up to {{ $maxRows }} rows per file.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    <a href="{{ route('dhiran.old-loans.template') }}" class="btn btn-secondary fm-add-borrower" data-turbo="false">
                        <x-icon name="download" size="16" /> Download template (CSV)
                    </a>
                    <details class="fm-cols">
                        <summary>What each column means</summary>
                        <dl>
                            @foreach($columns as $key => $meaning)
                                <div><dt>{{ $key }}</dt><dd>{{ $meaning }}</dd></div>
                            @endforeach
                        </dl>
                    </details>
                </div>
            </section>

            <form method="POST" action="{{ route('dhiran.old-loans.upload.store') }}" enctype="multipart/form-data" data-turbo-frame="_top"
                  class="fm-card" aria-labelledby="up-h-2"
                  x-data="{ name: '', busy: false }" @submit="busy = !$event.defaultPrevented" @pageshow.window="busy = false">
                @csrf
                <header class="fm-card-head">
                    <span class="fm-num" aria-hidden="true">2</span>
                    <div>
                        <h2 class="fm-title" id="up-h-2">Upload it</h2>
                        <p class="fm-desc">CSV or Excel (.xlsx). You will see every loan and any problems before anything is saved.</p>
                    </div>
                </header>
                <div class="fm-card-body">
                    <div class="fm-field">
                        <div class="fm-drop @error('file') is-invalid @enderror">
                            <x-icon name="cloud-upload" size="28" />
                            <strong x-text="name || 'Choose your file'">Choose your file</strong>
                            <span x-show="!name">Tap here, or drop a .csv or .xlsx file</span>
                            <input type="file" name="file" id="file" accept=".csv,.xlsx" required aria-label="Old loans file (CSV or Excel)"
                                   @error('file') aria-invalid="true" aria-describedby="err-file" @enderror
                                   @change="name = $event.target.files[0]?.name || ''">
                        </div>
                        @error('file')<p class="fm-error" id="err-file" role="alert"><x-icon name="circle-alert" size="16" />{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary fm-add-borrower" :disabled="busy" :aria-busy="busy.toString()">
                        <x-icon name="eye" size="16" /> <span x-text="busy ? 'Checking…' : 'Check the file'">Check the file</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-dhiran-layout>
