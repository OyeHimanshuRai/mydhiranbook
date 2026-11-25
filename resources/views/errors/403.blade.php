@php
    // The only 403 messages we show; any other message is ignored. Keys are exact abort() messages.
    $safe = [
        'Access ended' => 'Your access to MyDhiranBook has ended. Please contact support to renew it.',
        'No shop assigned.' => 'Your account is not linked to a shop yet. Ask the owner of your shop to add you.',
        'Pledge Loans is not enabled.' => 'Pledge Loans is not switched on for your shop. Please contact support.',
        'The shop owner account cannot be managed from staff management.' => 'The shop owner account cannot be changed from staff management.',
    ];
    $text = $safe[$exception->getMessage()] ?? 'Ask the owner of your shop to give you the permission for this page.';
@endphp
@include('errors._layout', ['code' => 403, 'title' => 'You do not have access to this page', 'text' => $text])
