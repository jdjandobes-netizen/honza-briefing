(() => {
  "use strict";
  let csrf='';
  const node=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=String(text);return n;};
  const request=async(method='GET',body=null)=>{
    const r=await fetch('api/emergency-settings.php',{method,credentials:'same-origin',cache:'no-store',headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:body?JSON.stringify(body):undefined});
    let d={};try{d=await r.json();}catch{} if(d.csrf)csrf=d.csrf;if(!r.ok)throw new Error(d.error||`HTTP ${r.status}`);return d;
  };
  const inject=async(dialog)=>{
    if(!dialog||dialog.querySelector('[data-emergency-ai-settings]'))return;
    let settings;try{settings=await request();}catch{return;}
    const box=node('section','emergency-ai-settings');box.dataset.emergencyAiSettings='1';
    box.append(node('h3',null,'AI pro japonské nouzové zprávy'));
    const status=node('p','podcast-small');
    const describe=()=>{status.textContent=settings.hasEmergencyAiKey?'Používá se samostatný šifrovaně uložený Gemini klíč pro překlady a safety interpretaci.':settings.usesPodcastKey?'Používá se stávající podcastový Gemini klíč jako fallback.':'Není dostupný žádný AI klíč; kritické alerty fungují, ale bez plného českého překladu.';};describe();box.append(status);
    const label=node('label','podcast-field','Samostatný Gemini API klíč pro emergency AI');const input=node('input');input.type='password';input.autocomplete='off';input.spellcheck=false;input.placeholder=settings.hasEmergencyAiKey?'Samostatný klíč je uložený — ponech prázdné bez změny':'Volitelné — prázdné = použít podcastový klíč';label.append(input);box.append(label);
    box.append(node('p','podcast-small','Klíč se ukládá do stejného šifrovaného privátního úložiště jako podcastový klíč. Hodnota se nikdy neposílá zpět do prohlížeče ani neukládá do GitHubu. Model: gemini-3.8-flash.'));
    const msg=node('p','podcast-form-message');box.append(msg);
    const actions=node('div','podcast-play-controls');
    const save=node('button','podcast-button','Uložit emergency klíč');save.type='button';save.addEventListener('click',async()=>{if(!input.value.trim()){msg.textContent='Vlož nový klíč, nebo použij tlačítko pro návrat k podcastovému klíči.';return;}save.disabled=true;try{settings=await request('POST',{emergencyAiApiKey:input.value});input.value='';describe();msg.textContent='Emergency AI klíč je uložený šifrovaně.';}catch(e){msg.textContent=e.message;}finally{save.disabled=false;}});actions.append(save);
    const fallback=node('button','podcast-secondary','Používat podcastový klíč');fallback.type='button';fallback.addEventListener('click',async()=>{fallback.disabled=true;try{settings=await request('POST',{removeEmergencyKey:true});describe();msg.textContent=settings.usesPodcastKey?'Samostatný klíč byl odstraněn; používá se podcastový klíč.':'Samostatný klíč byl odstraněn, ale podcastový klíč není nastavený.';}catch(e){msg.textContent=e.message;}finally{fallback.disabled=false;}});actions.append(fallback);box.append(actions);
    const form=dialog.querySelector('form'); if(form) form.insertAdjacentElement('afterend',box); else dialog.append(box);
  };
  const scan=()=>document.querySelectorAll('.podcast-dialog[open],dialog.podcast-dialog').forEach(inject);
  document.addEventListener('DOMContentLoaded',()=>{const obs=new MutationObserver(scan);obs.observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['open']});scan();});
})();
