import urllib.request,urllib.parse,http.cookiejar,json,re
base='http://127.0.0.1:8097';checks=[]
class Session:
 def __init__(self,host):self.host=host;self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.token=''
 def call(self,path,data=None,host=None):
  request=urllib.request.Request(base+path,urllib.parse.urlencode({'_csrf':self.token,**data}).encode() if data else None,headers={'Host':host or self.host})
  try:
   with self.opener.open(request) as response:status=response.status;body=response.read().decode();headers=response.headers
  except urllib.error.HTTPError as error:status=error.code;body=error.read().decode();headers=error.headers
  assert 'Warning:' not in body and 'Fatal error' not in body
  token=re.search(r'name="_csrf" value="([a-f0-9]+)"',body)
  if token:self.token=token.group(1)
  return status,body,headers
 def login(self,email):self.call('/login');return self.call('/login',{'email':email,'password':'TenantTestPassword123!'})
def check(label,result):
 assert result,label
 checks.append(label);print('PASS '+label,flush=True)
alpha=Session('alpha.test');bravo=Session('bravo.test')
_,_,headerA=alpha.call('/login');_,_,headerB=bravo.call('/login')
check('Real tenants use different session cookies',headerA['Set-Cookie'].split('=')[0]!=headerB['Set-Cookie'].split('=')[0])
check('Alpha tenant login',alpha.login('admin@alpha.test')[0]==200)
check('Bravo tenant login',bravo.login('admin@bravo.test')[0]==200)
body=alpha.call('/projects')[1];check('Alpha database records scoped','alpha private project' in body and 'bravo private project' not in body)
body=bravo.call('/projects')[1];check('Bravo database records scoped','bravo private project' in body and 'alpha private project' not in body)
body=alpha.call('/projects',host='bravo.test')[1];check('Alpha session cannot authenticate to Bravo','name="password"' in body)
check('Unknown tenant host rejected',alpha.call('/login',host='unknown.test')[0]==404)
check('Alpha cannot see Bravo candidate', 'bravo private candidate' not in alpha.call('/employees')[1])
json.dump({'separate_databases':True,'checks':checks,'https_production_tested':False,'concurrent_load_tested':False},open('work/tenant-runtime-results.json','w'),indent=2)
