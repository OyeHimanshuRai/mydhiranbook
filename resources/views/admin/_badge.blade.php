{{-- Status chip: always a word. $s = status text, optional $label. Tone: ok / warn / bad / neutral. --}}
@php $tone = ['active'=>'ok','paid'=>'ok','resolved'=>'ok','enabled'=>'ok','grace'=>'warn','pending'=>'warn','open'=>'bad','suspended'=>'bad','inactive'=>'bad','expired'=>'bad','cancelled'=>'bad'][strtolower((string)$s)] ?? 'neutral'; @endphp
<span class="adm-badge adm-badge--{{ $tone }}">{{ $label ?? \Illuminate\Support\Str::headline((string)$s) }}</span>
