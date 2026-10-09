import json,time,urllib.request,urllib.parse,http.cookiejar,re
fixture=json.load(open('work/scale-fixture.json'));base='http://127.0.0.1:8097'
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def fetch(path,data=None):
 request=urllib.request.Request(base+path,urllib.parse.urlencode(data).encode() if data else None)
 started=time.perf_counter()
 with opener.open(request,timeout=60) as response:body=response.read().decode();status=response.status
 assert status==200 and 'Fatal error' not in body and 'Warning:' not in body,path
 return body,round(time.perf_counter()-started,3)
body,_=fetch('/login');token=re.search(r'name="_csrf" value="([a-f0-9]+)"',body).group(1)
fetch('/login',{'_csrf':token,'email':'admin@test.invalid','password':'TestPassword123!'})
metrics=[]
for path in ['/overview','/employees','/employees?q=synthetic-10-1000','/candidates?q=synthetic']:
 body,elapsed=fetch(path);metrics.append({'route':path,'seconds':elapsed,'html_bytes':len(body.encode())})
body,_=fetch('/projects');token=re.search(r'name="_csrf" value="([a-f0-9]+)"',body).group(1)
fetch('/select-project',{'_csrf':token,'job_id':fixture['projects'][0]})
for path in ['/manning','/roster','/recruitment','/recruitment?page=10','/hours','/organization','/client-orders']:
 body,elapsed=fetch(path);metrics.append({'route':path,'seconds':elapsed,'html_bytes':len(body.encode())})
 if path=='/recruitment?page=10':assert 'Synthetic worker' in body,'Recruitment page 10 unavailable'
result={'synthetic_workers':10000,'projects':10,'workers_per_project':1000,'single_user_sequential_requests':True,'concurrent_load_certified':False,'requests':metrics}
json.dump(result,open('work/scale-results.json','w'),indent=2)
print('PASS 10,000 synthetic dossiers across 10 projects; sequential page checks only',flush=True)
