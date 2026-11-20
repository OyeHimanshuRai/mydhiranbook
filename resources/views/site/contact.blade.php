@extends('site.layout') @section('title','Contact')
@section('content')
<section class="band prose"><h1>Contact</h1><p>Questions about plans, payments, refunds or your data? Write to us and we will reply by email.</p>
<dl><dt>Business</dt><dd><x-site.biz key="legal_name"/></dd><dt>Email</dt><dd><x-site.biz key="email"/></dd><dt>Phone</dt><dd><x-site.biz key="phone"/></dd><dt>Address</dt><dd><x-site.biz key="address"/></dd></dl></section>
@endsection
