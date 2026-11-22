{{-- Tiny shared behaviour: show/hide password, caps-lock hint, double-submit guard, mobile menu. Guarded so Turbo visits do not stack listeners. --}}
<script>
if(!window.__ui){window.__ui=1;var d=document,busy=function(f,on){var b=f.querySelector('[type=submit]');f.dataset.busy=on?1:'';if(!b)return;if(on){b.dataset.label=b.textContent;b.setAttribute('aria-busy','true');if(b.dataset.busy)b.textContent=b.dataset.busy}else{b.removeAttribute('aria-busy');if(b.dataset.label)b.textContent=b.dataset.label}};
d.addEventListener('click',function(e){var r=e.target.closest('[data-reveal]'),m=e.target.closest('[data-menu]');
if(r){var i=d.getElementById(r.getAttribute('aria-controls')),s=i.type==='password',w=s?'Hide':'Show';i.type=s?'text':'password';r.textContent=w;r.setAttribute('aria-label',w+' password')}
if(m){var o=m.getAttribute('aria-expanded')!=='true';m.setAttribute('aria-expanded',o);d.getElementById(m.getAttribute('aria-controls')).classList.toggle('is-open',o)}});
d.addEventListener('submit',function(e){var f=e.target;if(!f.hasAttribute||!f.hasAttribute('data-once'))return;if(f.dataset.busy){e.preventDefault();return}busy(f,true)});
['keydown','keyup'].forEach(function(n){d.addEventListener(n,function(e){var t=e.target,h=t.type==='password'&&e.getModifierState&&t.form&&t.form.querySelector('[data-caps]');if(h)h.hidden=!e.getModifierState('CapsLock')})});
d.addEventListener('keydown',function(e){if(e.key==='Escape'){var m=d.querySelector('[data-menu][aria-expanded=true]');if(m){m.click();m.focus()}}});
addEventListener('pageshow',function(){d.querySelectorAll('form[data-busy]').forEach(function(f){busy(f,false)})})}
var x=document.querySelector('[aria-invalid=true]');if(x)x.focus();
</script>
