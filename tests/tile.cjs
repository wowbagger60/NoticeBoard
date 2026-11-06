// Run with jsdom installed outside the production library.
const { JSDOM } = require('jsdom');
const fs = require('fs');
const assert = require('node:assert/strict');
const template = fs.readFileSync(__dirname + '/../NoticeBoard/assets/index.html', 'utf8');
const payload = '</script><script>window.injected=true</script>&"';
const item = {Type:'message',Sequence:1,TargetID:12345,Level:'Alarm',Color:'#E53935',Message:payload,TimestampText:'01.11. 12:00',Deletable:true};
const data = {Items:[{...item,Type:'status',Deletable:false}, item],Grouped:true,ShowTimestamp:true,EmptyText:'Leer'};
const initial = JSON.stringify(data).replaceAll('<','\\u003C');
const dom = new JSDOM(template.replace('/*DATA*/', 'let data = ' + initial + ';'), {
    url:'https://example.test/?margintop=0&marginside=0&marginbottom=0',runScripts:'dangerously',
    beforeParse(window) {
        window.calls=[];window.requestAction=(...args)=>window.calls.push(args);
        window.translate=s=>s;window.openObject=id=>window.target=id;
    }
});
const w=dom.window,d=w.document;
assert.equal(d.querySelectorAll('.item').length,2);
assert.equal(d.querySelectorAll('.group-separator').length,1);
assert.equal(d.querySelector('.message').textContent,payload);
assert.equal(w.injected,undefined);
assert.equal(d.body.style.paddingTop,'0px');
d.querySelector('.item').click();assert.equal(w.target,12345);
w.target=0;d.querySelector('.delete').click();assert.equal(w.target,0);assert.deepEqual(Array.from(w.calls.at(-1)),['DeleteMessage',1]);
const list=d.getElementById('list');list.scrollTop=125;
w.handleMessage(JSON.stringify({Type:'Render',Data:data}));assert.equal(list.scrollTop,125);
w.handleMessage({Type:'Render',Data:{...data,Grouped:false,ShowTimestamp:false}});
assert.equal(d.querySelectorAll('.group-separator').length,0);assert.equal(d.querySelectorAll('.time').length,0);
w.handleMessage('invalid');assert.equal(d.querySelectorAll('.item').length,2);
w.handleMessage({Type:'Render',Data:{Items:[],EmptyText:'Leer'}});assert.equal(d.querySelector('.empty span').textContent,'Leer');
console.log('PASS: tile DOM rendering, text safety, navigation, delete propagation, scroll position, grouping, timestamps, live updates');
w.close();
