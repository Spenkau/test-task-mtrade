"use strict";
(() => {
  const $ = id => document.getElementById(id);
  const WINDOW = 120000, MAX = 1000;
  let buffer = [], snapshot = null, orders = [];
  const session = globalThis.crypto?.randomUUID?.() ?? `demo-${Date.now()}`;
  const start = performance.now();
  const names = {new:"Новый", confirmed:"Подтверждён", cancelled:"Отменён"};
  function prune() {
    const cutoff = performance.now() - WINDOW;
    buffer = buffer.filter(e => e.offset_ms >= cutoff - start).slice(-MAX);
  }
  function record(type, data = {}) {
    prune();
    buffer.push({time: new Date().toISOString(), offset_ms: Math.round(performance.now()-start), type, ...data});
    if (buffer.length > MAX) buffer.shift();
    showLog();
  }
  function showLog() {
    $('bufferCount').textContent = `${buffer.length} событий · максимум ${MAX}`;
    const list = buffer.slice(-30).map(e => {
      const div = document.createElement('div'); div.className = 'event';
      div.textContent = JSON.stringify(e); return div;
    });
    $('timeline').replaceChildren(...list);
    $('timeline').scrollTop = $('timeline').scrollHeight;
  }
  const seed = [
    {id:'MP-24001',status:'new',buyer:{name:'Покупатель 100'},items:[{sku:'TS-1001',title:'Блокнот А5 клетка',quantity:2,price:3.2},{sku:'TS-1002',title:'Ручка шариковая синяя',quantity:5,price:1.1}],total:11.9,currency:'BYN'},
    {id:'MP-24003',status:'new',buyer:{name:'Покупатель 102'},items:[{sku:'TS-1004',title:'Шредер офисный',quantity:1,price:1150},{sku:'TS-1011',title:'Клей-карандаш 21 г',quantity:3,price:1.95}],total:1155.85,currency:'BYN'},
    {id:'MP-24028',status:'confirmed',buyer:{name:'Покупатель 127'},items:[],total:0,currency:'BYN'},
    {id:'MP-24030',status:'new',buyer:{name:'Покупатель 129'},items:[{sku:'TS-1021',title:'Конверт С4',quantity:2,price:.95},{sku:'TS-1003',title:'Скотч прозрачный 48 мм',quantity:1}],total:null,currency:'BYN'}
  ];
  function text(value, fallback='—') { return typeof value === 'string' || typeof value === 'number' ? String(value) : fallback; }
  function money(value, currency) { return typeof value === 'number' && Number.isFinite(value) ? `${value.toLocaleString('ru-RU',{minimumFractionDigits:2,maximumFractionDigits:2})} ${text(currency)}` : 'Не указана'; }
  function cell(value) {const td=document.createElement('td');td.textContent=value;return td;}
  function render() {
    const q=$('search').value.trim().toLowerCase(), filter=$('status').value;
    $('count').textContent=orders.length;
    $('unique').textContent=new Set(orders.map(o=>o.id)).size;
    $('cancelled').textContent=orders.filter(o=>o.status==='cancelled').length;
    const rows=[];
    orders.forEach((o,index)=>{
      if(!o.id.toLowerCase().includes(q) || (filter!=='all' && o.status!==filter))return;
      const tr=document.createElement('tr');
      tr.append(cell(o.id),cell(text(o.buyer?.name)),cell(names[o.status]??text(o.status)),cell(String(Array.isArray(o.items)?o.items.length:0)),cell(money(o.total,o.currency)));
      const td=document.createElement('td'), button=document.createElement('button');
      button.className='secondary'; button.textContent='Открыть';
      button.addEventListener('click',()=>{record('action',{target:'open_order',row:index+1});openDetails(o);});
      td.append(button);tr.append(td);rows.push(tr);
    });
    if(!rows.length){const tr=document.createElement('tr'),td=cell('Нет подходящих заказов');td.colSpan=6;tr.append(td);rows.push(tr);}
    $('orders').replaceChildren(...rows);
  }
  function openDetails(order) {
    const title=document.createElement('p');title.textContent=order.id;
    const list=document.createElement('ul');
    const items=Array.isArray(order.items)?order.items:[];
    items.forEach(item=>{const li=document.createElement('li');li.textContent=item&&typeof item==='object'?`${text(item.sku)} · ${text(item.title)} · ${text(item.quantity)} × ${money(item.price,order.currency)}`:'Повреждённая позиция';list.append(li);});
    if(!items.length){const li=document.createElement('li');li.textContent='В заказе нет позиций';list.append(li);}
    $('detailContent').replaceChildren(title,list);$('details').showModal();
  }
  document.addEventListener('input',event=>{
    if(!(event.target instanceof Element))return;
    const field=event.target.closest('[data-record]');
    if(field) record('input',{target:field.dataset.record,value:'[MASKED]'});
  });
  $('search').addEventListener('input',render);
  $('status').addEventListener('change',()=>{record('filter',{target:'status',value:$('status').value});render();});
  $('closeDetails').onclick=()=>{$('details').close();record('action',{target:'close_order'});};
  $('upload').addEventListener('change',async()=>{
    const file=$('upload').files[0];if(!file)return;
    record('action',{target:'load_orders_file'});
    try{
      if(file.size>5*1024*1024)throw new Error('limit');
      const data=JSON.parse((await file.text()).replace(/^\uFEFF/,''));
      if(!Array.isArray(data?.data?.orders))throw new Error('format');
      const valid=data.data.orders.filter(o=>o&&typeof o==='object'&&typeof o.id==='string');
      orders=valid;
      $('source').textContent='Локальный JSON: data.orders';
      $('notice').textContent=`Загружено ${valid.length}. Пропущено некорректных записей: ${data.data.orders.length-valid.length}. Повторы номеров сохранены.`;
      $('error').classList.add('hidden');render();record('result',{target:'load_orders_file',result:'success'});
    }catch{
      $('error').textContent='Файл не загружен. Нужен JSON с массивом data.orders размером до 5 МБ.';$('error').classList.remove('hidden');
      record('error',{code:'INVALID_ORDERS_FILE',target:'load_orders_file'});
    }
    $('upload').value='';
  });
  $('sync').onclick=async()=>{
    if($('sync').disabled)return;
    $('sync').disabled=true;record('action',{target:'sync_orders'});
    const begin=performance.now();
    record('request',{method:'POST',route:'sync_orders',simulated:true});
    await new Promise(resolve=>setTimeout(resolve,650));
    record('response',{method:'POST',route:'sync_orders',status:503,duration_ms:Math.round(performance.now()-begin),simulated:true});
    $('error').textContent='Демо: синхронизация недоступна (503). Нажмите «Пожаловаться на проблему» — повторять действия не нужно.';
    $('error').classList.remove('hidden');$('sync').disabled=false;
  };
  $('complaint').onclick=()=>{
    record('action',{target:'open_support'});prune();
    snapshot={schema_version:1,session_id:session,created_at:new Date().toISOString(),app_version:'prototype-1',page:'orders-demo',window_seconds:120,privacy:{input_values:'masked',dom_text:'not_collected',request_bodies:'not_collected',headers:'not_collected',urls:'not_collected'},events:buffer.map(e=>({...e}))};
    $('preview').textContent=JSON.stringify(snapshot,null,2);$('ticket').showModal();
  };
  $('closeTicket').onclick=()=>{$('ticket').close();snapshot=null;};
  $('download').onclick=()=>{
    if(!snapshot)return;
    const ticket={...snapshot,description:$('description').value};
    const blob=new Blob([JSON.stringify(ticket,null,2)],{type:'application/json;charset=utf-8'});
    const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=`support-${Date.now()}.json`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  };
  $('clear').onclick=()=>{buffer=[];showLog();};
  // Не сохраняем сообщения, stack trace и URL: в них могут находиться данные пользователя.
  window.addEventListener('error',()=>record('error',{code:'UNHANDLED_JS_ERROR'}));
  window.addEventListener('unhandledrejection',()=>record('error',{code:'UNHANDLED_PROMISE_REJECTION'}));
  setInterval(()=>{prune();showLog();},1000);
  orders=seed;render();$('notice').textContent='Встроены 4 учебных заказа. Для полного набора выберите orders.json из первого задания.';
  record('navigation',{route:'orders'});
})();
