@props(['key'])
{{ config('business.'.$key) ?: '['.(['legal_name' => 'legal business name', 'name' => 'business name'][$key] ?? 'business '.$key).']' }}
