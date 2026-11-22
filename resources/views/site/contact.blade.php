@extends('site.layout') @section('title','Contact')
@section('content')
<section class="band prose"><h1>Contact</h1><p>Questions about plans, payments, refunds or your data? Write to us and we will reply by email.</p>
<dl class="contact-list"><div><dt>Business</dt><dd><x-site.biz key="legal_name"/></dd></div><div><dt>Email</dt><dd>@if(config('business.email'))<a href="mailto:{{ config('business.email') }}"><x-site.biz key="email"/></a>@else<x-site.biz key="email"/>@endif</dd></div><div><dt>Phone</dt><dd><x-site.biz key="phone"/></dd></div><div><dt>Address</dt><dd><x-site.biz key="address"/></dd></div></dl></section>
@endsection
