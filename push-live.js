(() => {
  "use strict";
  const button = document.querySelector("#notification-button");
  if (!button || !("serviceWorker" in navigator) || !("PushManager" in window) || !("Notification" in window)) return;
  const api = (action) => `api/push.php?action=${encodeURIComponent(action)}`;
  const b64 = (value) => {
    const encoded=String(value||'').replace(/-/g,'+').replace(/_/g,'/');
    const raw=atob(encoded+'='.repeat((4-encoded.length%4)%4));
    return Uint8Array.from(raw,c=>c.charCodeAt(0));
  };
  const request = async (action, body) => {
    const response=await fetch(api(action),{method:body?'POST':'GET',headers:body?{'Content-Type':'application/json'}:{},body:body?JSON.stringify(body):undefined,cache:'no-store',credentials:'same-origin'});
    let data={}; try{data=await response.json();}catch{}
    if(!response.ok) throw new Error(data.error||`HTTP ${response.status}`);
    return data;
  };
  let cfg=null;
  const refresh = async () => {
    try{
      cfg=await request('config');
      if(!cfg.enabled||!cfg.publicKey){button.hidden=true;return;}
      const reg=await navigator.serviceWorker.ready;
      const sub=await reg.pushManager.getSubscription();
      button.hidden=false;
      button.disabled=false;
      button.textContent=sub?'Nouzové push zapnuté':'Zapnout nouzové push';
      button.title=sub?'Zařízení je přihlášené k nouzovým upozorněním z Japonska.':'Zapnout nouzová upozornění pro Japonsko.';
    }catch{button.hidden=true;}
  };
  button.addEventListener('click',async()=>{
    if(!cfg?.enabled) return;
    button.disabled=true;button.textContent='Zapínám…';
    try{
      const permission=await Notification.requestPermission();
      if(permission!=='granted') throw new Error('Upozornění nebyla povolena.');
      const reg=await navigator.serviceWorker.ready;
      let sub=await reg.pushManager.getSubscription();
      if(!sub) sub=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:b64(cfg.publicKey)});
      await request('subscribe',{subscription:sub.toJSON()});
      button.textContent='Nouzové push zapnuté';button.title='Zařízení je přihlášené k nouzovým upozorněním z Japonska.';
    }catch(e){button.textContent='Zkusit znovu';button.title=e?.message||'Push se nepodařilo zapnout';button.disabled=false;}
  });
  window.addEventListener('load',refresh);
})();
