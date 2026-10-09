const fs = require('fs');
const path = require('path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async()=>{
 const fixture=JSON.parse(fs.readFileSync('work/fixture.json','utf8'));
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_BIN || undefined});
 const issues=[];const context=await browser.newContext({viewport:{width:1440,height:1000}});const page=await context.newPage();
 page.on('pageerror',error=>issues.push(error.message));
 await page.goto('http://127.0.0.1:8097/login');await page.locator('input[name=email]').fill('admin@test.invalid');await page.locator('input[name=password]').fill('TestPassword123!');
 await Promise.all([page.waitForURL('http://127.0.0.1:8097/'),page.locator('button[type=submit]').click()]);
 await page.locator('select[name=job_id]').selectOption(String(fixture.a));await page.locator('form[action="/select-project"] button').click();
 for(const [route,name] of [['/contracts','contracts-desktop'],['/manning','manning-desktop'],['/overview','executive-overview'],['/organization','organization-chart'],['/requisitions','requisitions-desktop'],['/channels','recruitment-channels'],['/agency-setup','agency-setup'],['/qualifications','qualifications'],['/screening-workflow','screening-workflow'],['/offboarding','offboarding'],['/safety-plan','safety-plan'],['/account-security','account-security']]) {
  await page.goto('http://127.0.0.1:8097'+route);await page.screenshot({path:path.resolve('outputs/'+name+'.png'),fullPage:true});
 }
 await page.goto('http://127.0.0.1:8097/requisitions');await page.waitForSelector('.application-qr img');
 if(await page.locator('.application-qr canvas').count()<1) throw Error('Application QR failed to render');
 console.log('PASS QR canvas renders');
 const clientContext=await browser.newContext({viewport:{width:1440,height:1000}});const cp=await clientContext.newPage();cp.on('pageerror',e=>issues.push(e.message));
 // Restore the synthetic grant revoked by the HTTP isolation test.
 await page.goto('http://127.0.0.1:8097/client-access');await page.locator('form').filter({has:page.locator('input[value="grant"]')}).locator('select[name=user_id]').selectOption(String(fixture.clientUser));
 const grant=page.locator('form').filter({has:page.locator('input[value="grant"]')});await grant.locator('select[name=client_id]').selectOption(String(fixture.client));await grant.locator('button').click();
 await cp.goto('http://127.0.0.1:8097/login');await cp.locator('input[name=email]').fill('client@test.invalid');await cp.locator('input[name=password]').fill('TestPassword123!');await cp.locator('button[type=submit]').click();
 await cp.goto('http://127.0.0.1:8097/client-portal?job_id='+fixture.a);await cp.screenshot({path:path.resolve('outputs/client-portal-desktop.png'),fullPage:true});
 for(const [locale,label] of [['fr','Demandes clients'],['es','Solicitudes de clientes']]) {
  await cp.locator('#lang-pick').selectOption(locale);await cp.waitForLoadState('networkidle');
  if(!cp.url().includes('job_id='+fixture.a))throw Error('Language switch lost selected client project');
  if(!(await cp.locator('main').textContent()).includes(label))throw Error('Client portal translation failed: '+locale);
  await cp.screenshot({path:path.resolve('outputs/client-portal-'+locale+'.png'),fullPage:true});
 }
 await cp.setViewportSize({width:390,height:844});
 if(await cp.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw Error('Client portal mobile overflow');
 await cp.screenshot({path:path.resolve('outputs/client-portal-mobile.png'),fullPage:true});
 const mobile=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});const mp=await mobile.newPage();
 mp.on('pageerror',error=>issues.push(error.message));
 await mp.goto('http://127.0.0.1:8097/login');await mp.locator('input[name=email]').fill('worker@test.invalid');await mp.locator('input[name=password]').fill('TestPassword123!');
 await Promise.all([mp.waitForURL('http://127.0.0.1:8097/portal'),mp.locator('button[type=submit]').click()]);
 await mp.screenshot({path:path.resolve('outputs/worker-portal-mobile.png'),fullPage:true});
 const overflow=await mp.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+1);
 if(overflow) throw Error('Mobile portal has horizontal overflow');
 console.log('PASS Mobile portal has no horizontal overflow');
 await mp.goto('http://127.0.0.1:8097/contracts');
 if(await mp.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+1))throw Error('Mobile contracts have horizontal overflow');
 await mp.screenshot({path:path.resolve('outputs/contracts-mobile.png'),fullPage:true});
 if(issues.length) throw Error(issues.join('\n'));
 fs.writeFileSync('work/browser-results.json',JSON.stringify({qr:true,mobileOverflow:false,javascriptErrors:issues},null,2));
 console.log('PASS Desktop/mobile browser checks and screenshots');await browser.close();
})().catch(error=>{console.error(error);process.exit(1)});
