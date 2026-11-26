@extends('site.layout')
@section('title', 'Contact')

@section('content')
<section class="lp-subhero">
    <div class="lp-in lp-subhero-grid">
        <div>
            <h1>Contact us</h1>
            <p class="lp-lead">
                Questions about your shop access, onboarding, or your data? Write to us by email and we will reply directly.
            </p>
        </div>

        <div class="lp-sheet-frame lp-contact-sheet">
            <dl class="lp-kv">
                <div>
                    <dt>Support email</dt>
                    <dd>
                        @if(config('business.email'))
                            <a href="mailto:{{ config('business.email') }}"><x-site.biz key="email"/></a>
                        @else
                            <x-site.biz key="email"/>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</section>
@endsection
