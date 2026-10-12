"""P5-M05 through the screens: thanks between colleagues (who counts as
one, points once a month, the daily limit, the wall and private thanks);
award programmes, nominations and winners (a worker's win written into the
HR record, the period closed, nobody deciding their own); points and the
rewards catalogue (held on request, given back on decline or cancel, stock,
eligibility); a cash reward paid by payroll in an open period as a payroll
adjustment, once; a time-off reward added to the leave allowance; feedback
given, shared or not, and asked for; anonymous engagement surveys (no name,
no order, results held under the minimum, groups broken out only at it), a
named survey, reminders, closing by the scheduled workflows; who may see
and do what; translations.
"""
import datetime, http.cookiejar, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request

base = 'http://127.0.0.1:8097'
fx = json.load(open('work/engagement.json'))
php = os.environ.get('PHP_BIN', 'php')
IDS, CANDS = fx['ids'], fx['cands']
TODAY = datetime.date.today()
day = lambda n: (TODAY + datetime.timedelta(days=n)).isoformat()
MONTH = TODAY.strftime('%Y-%m')
results = []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None
        self.final = None

    def open(self, req):
        try:
            with self.opener.open(req) as r:
                self.final = urllib.parse.urlparse(r.geturl()).path
                return r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()

    def get(self, path):
        status, body = self.open(base + path)
        m = re.search(r'name="_csrf" value="([a-f0-9]+)"', body)
        if m:
            self.token = m[1]
        assert 'Fatal error' not in body and 'Warning:' not in body and 'Notice:' not in body and 'Deprecated:' not in body, body[:800]
        return status, body

    def post(self, path, values):
        data = urllib.parse.urlencode([('_csrf', self.token)] + (values if isinstance(values, list) else list(values.items())), doseq=True).encode()
        status, body = self.open(urllib.request.Request(base + path, data))
        assert 'Fatal error' not in body and 'Warning:' not in body, body[:800]
        return status, body


def user(key):
    c = Client()
    c.get('/login')
    status, body = c.post('/login', {'email': 'en-%s@test.invalid' % key, 'password': 'TestPassword123!'})
    assert '<title>Sign in' not in body, key
    return c


def check(label, condition, detail=''):
    assert condition, label + (' - ' + str(detail)[:600] if detail else '')
    results.append(label)
    print('PASS', label, flush=True)


def probe(*args):
    out = subprocess.run([php, 'work/engagement_probe.php', *map(str, args)], check=True, capture_output=True, text=True).stdout
    return json.loads(out) if out.strip() else None


def balance(key):
    return int(probe()['balances'].get(str(IDS[key]), 0))


def act(c, path, values):
    c.get(path)
    return c.post(path, values)


def refused(c, path, values, words):
    status, body = act(c, path, values)
    return status == 422 and words in body


rec, rec2, pay, hotels, sup = user('rec'), user('rec2'), user('pay'), user('hotels'), user('sup')
w1, w2, w3, wb = user('w1'), user('w2'), user('w3'), user('wb')
R = '/recognition'

# ── settings ───────────────────────────────────────────────────────────
check('1001 points for a thank-you is refused', refused(rec, R, {'do': 'settings_save', 'kudos_points': '1001', 'kudos_daily_limit': '3'}, '0 to 1000'))
check('and a daily limit of 0', refused(rec, R, {'do': 'settings_save', 'kudos_points': '10', 'kudos_daily_limit': '0'}, '1 to 50'))
act(rec, R, {'do': 'settings_save', 'kudos_points': '10', 'kudos_daily_limit': '3'})
check('A recruiter sets 10 points a thank-you and 3 thank-yous a day', probe()['settings'] == {'kudos_points': '10', 'kudos_daily_limit': '3'})
check('A worker does not change the settings', act(w1, R, {'do': 'settings_save', 'kudos_points': '1000', 'kudos_daily_limit': '50'})[0] == 403
      and probe()['settings']['kudos_points'] == '10')

# ── thanks ─────────────────────────────────────────────────────────────
status, body = w1.get(R)
check('A worker can thank their crew and supervisor, not a worker on another project', all('<option value="%d">' % IDS[k] in body.split('id="kudos-form"')[1].split('</form>')[0] for k in ('w2', 'w3', 'sup'))
      and '<option value="%d">' % IDS['wb'] not in body.split('id="kudos-form"')[1].split('</form>')[0])
check('Thanking a worker on another project is refused', refused(w1, R, {'do': 'kudos', 'to_user_id': IDS['wb'], 'message': 'Thanks', 'is_public': '1'}, 'people you work with'))
check('and thanking yourself', refused(w1, R, {'do': 'kudos', 'to_user_id': IDS['w1'], 'message': 'Thanks', 'is_public': '1'}, 'someone else'))
act(w1, R, {'do': 'kudos', 'to_user_id': IDS['w2'], 'message': 'Caught the leak on line 4 before it spread', 'is_public': '1'})
k = probe()['kudos'][-1]
check('A worker thanks a crewmate, who gets 10 points and is told', int(k['to_user_id']) == IDS['w2'] and int(k['points']) == 10 and balance('w2') == 10
      and any(int(n['user_id']) == IDS['w2'] and 'thanked you' in n['message'] for n in probe()['notifications']))
act(w1, R, {'do': 'kudos', 'to_user_id': IDS['w2'], 'message': 'Again: great work on the night shift', 'is_public': '1'})
check('A second thanks from the same person that month brings no points: thanks cannot be traded for points', int(probe()['kudos'][-1]['points']) == 0 and balance('w2') == 10)
act(w1, R, {'do': 'kudos', 'to_user_id': IDS['w3'], 'message': 'Thanks for covering my Friday', 'is_public': ''})
check('A fourth thank-you in a day is refused', refused(w1, R, {'do': 'kudos', 'to_user_id': IDS['sup'], 'message': 'Thanks boss', 'is_public': '1'}, '3 thank-yous today'))
private_id = probe()['kudos'][-1]['id']
public_id = probe()['kudos'][0]['id']
status, body = w2.get(R)
check('The crewmate sees the public thanks on the wall, and not the private one to someone else', 'data-kudos="%s"' % public_id in body and 'data-kudos="%s"' % private_id not in body)
status, body = w3.get(R)
check('The one thanked privately sees it', 'data-kudos="%s" data-public="0"' % private_id in body)
status, body = wb.get(R)
check('A worker on another project sees neither', 'data-kudos="%s"' % public_id not in body and 'data-kudos="%s"' % private_id not in body)
status, body = rec.get(R)
check('A recruiter sees the public thanks, not the private one', 'data-kudos="%s"' % public_id in body and 'data-kudos="%s"' % private_id not in body)

# ── awards ─────────────────────────────────────────────────────────────
check('An award with a two-letter name is refused', refused(rec, R, {'do': 'program_save', 'name': 'CM', 'period': 'month', 'eligible': 'workers', 'nominated_by': 'everyone', 'points': '50'}, '3 to 190'))
check('and one carrying 100,001 points', refused(rec, R, {'do': 'program_save', 'name': 'Crew member of the month', 'period': 'month', 'eligible': 'workers', 'nominated_by': 'everyone', 'points': '100001'}, '100,000'))
act(rec, R, {'do': 'program_save', 'name': 'Crew member of the month', 'description': 'Safe, steady, helpful', 'period': 'month', 'eligible': 'workers', 'nominated_by': 'everyone', 'points': '50'})
act(rec, R, {'do': 'program_save', 'name': 'Desk star', 'period': 'month', 'eligible': 'staff', 'nominated_by': 'staff', 'points': '0'})
act(rec, R, {'do': 'program_save', 'name': 'Long service', 'period': 'once', 'eligible': 'everyone', 'nominated_by': 'staff', 'points': '20'})
P = {p['name']: int(p['id']) for p in probe()['programs']}
check('Three awards: monthly for workers, monthly for staff from staff, and once per person', {'Crew member of the month', 'Desk star', 'Long service'} <= set(P))
check('A worker does not create an award', act(w1, R, {'do': 'program_save', 'name': 'Mine', 'period': 'month', 'eligible': 'workers', 'nominated_by': 'everyone'})[0] == 403)
CM = P['Crew member of the month']
check('A worker does not nominate for an award nominated by staff', refused(w1, R, {'do': 'nominate', 'program_id': P['Desk star'], 'nominee_user_id': IDS['w2'], 'reason': 'Always on time and careful'}, 'come from staff'))
check('A nomination with a short reason is refused', refused(w1, R, {'do': 'nominate', 'program_id': CM, 'nominee_user_id': IDS['w2'], 'reason': 'Good'}, '10 to 1000'))
check('and a supervisor for an award for workers', refused(w1, R, {'do': 'nominate', 'program_id': CM, 'nominee_user_id': IDS['sup'], 'reason': 'Runs a tidy, safe site'}, 'for workers'))
act(w1, R, {'do': 'nominate', 'program_id': CM, 'nominee_user_id': IDS['w2'], 'reason': 'Caught the leak before it spread'})
check('A worker nominates a crewmate for this month', probe()['nominations'][-1]['period_key'] == MONTH and probe()['nominations'][-1]['status'] == 'nominated')
check('Nominating the same person twice in a month is refused', refused(w1, R, {'do': 'nominate', 'program_id': CM, 'nominee_user_id': IDS['w2'], 'reason': 'Caught the leak before it spread'}, 'already nominated'))
act(w3, R, {'do': 'nominate', 'program_id': CM, 'nominee_user_id': IDS['w2'], 'reason': 'Trained two new starters this month'})
act(sup, R, {'do': 'nominate', 'program_id': CM, 'nominee_user_id': IDS['w1'], 'reason': 'No incident, first on site every day'})
noms = probe()['nominations']
w2nom = next(n for n in noms if int(n['nominee_user_id']) == IDS['w2'])
w1nom = next(n for n in noms if int(n['nominee_user_id']) == IDS['w1'])
check('A worker does not choose the winner', act(w1, R, {'do': 'award', 'nomination_id': w2nom['id']})[0] == 403)
status, body = act(rec, R, {'do': 'award', 'nomination_id': w2nom['id']})
d = probe()
rec_line = [r for r in d['recognitions'] if int(r['candidate_id']) == CANDS['w2']]
check('The recruiter chooses the winner: both nominations of them are won, the other of the month is not selected',
      [n['status'] for n in d['nominations'] if int(n['program_id']) == CM and int(n['nominee_user_id']) == IDS['w2']] == ['won', 'won']
      and next(n for n in d['nominations'] if n['id'] == w1nom['id'])['status'] == 'not_selected')
check('The win is written into the worker\'s HR record as an award, through the existing recognition', len(rec_line) == 1 and rec_line[0]['kind'] == 'award'
      and rec_line[0]['title'] == 'Crew member of the month · ' + MONTH and int(rec_line[0]['created_by']) == IDS['rec']
      and int(next(n for n in d['nominations'] if n['id'] == w2nom['id'])['recognition_id']) == int(rec_line[0]['id'])
      and any(e['detail'].endswith('Crew member of the month · ' + MONTH) for e in d['candidate_events'] if int(e['candidate_id']) == CANDS['w2']))
check('The winner gets the award\'s 50 points, once', balance('w2') == 60 and len([p for p in d['points'] if int(p['user_id']) == IDS['w2'] and p['source'] == 'award']) == 1)
check('The winner and both nominators are told', any(int(n['user_id']) == IDS['w2'] and 'recognised' in n['message'] for n in d['notifications'])
      and all(any(int(n['user_id']) == IDS[k] and 'Your nominee won' in n['message'] for n in d['notifications']) for k in ('w1', 'w3')))
status, body = w2.get('/hr-records')
check('The worker sees it in their HR record', 'Crew member of the month · ' + MONTH in body)
check('A new nomination once the month\'s award is given is refused', refused(w3, R, {'do': 'nominate', 'program_id': CM, 'nominee_user_id': IDS['w1'], 'reason': 'No incident, first on site every day'}, 'has been given'))
check('and choosing a second winner of the month', refused(rec, R, {'do': 'award', 'nomination_id': w1nom['id']}, 'decided'))
act(pay, R, {'do': 'nominate', 'program_id': P['Desk star'], 'nominee_user_id': IDS['hotels'], 'reason': 'Found rooms for forty overnight'})
act(rec, R, {'do': 'award', 'nomination_id': probe()['nominations'][-1]['id']})
d = probe()
check('A member of staff wins a staff award: told, and no HR record line, since staff have none',
      d['nominations'][-1]['status'] == 'won' and d['nominations'][-1]['recognition_id'] is None and any(int(n['user_id']) == IDS['hotels'] and n['message'].startswith('You won') for n in d['notifications']))
act(rec2, R, {'do': 'nominate', 'program_id': P['Long service'], 'nominee_user_id': IDS['rec'], 'reason': 'Ten years on the desk this spring'})
ls = probe()['nominations'][-1]['id']
check('A recruiter does not decide an award they are nominated for', refused(rec, R, {'do': 'award', 'nomination_id': ls}, 'Someone else decides'))
act(rec2, R, {'do': 'award', 'nomination_id': ls})
check('Another recruiter does, and the 20 points follow', probe()['nominations'][-1]['status'] == 'won' and balance('rec') == 20)
check('An award given once per person is not given to them again', refused(pay, R, {'do': 'nominate', 'program_id': P['Long service'], 'nominee_user_id': IDS['rec'], 'reason': 'Ten years on the desk this spring'}, 'already won'))
check('Long service is for everyone, but a worker does not nominate for it', refused(w3, R, {'do': 'nominate', 'program_id': P['Long service'], 'nominee_user_id': IDS['w1'], 'reason': 'Fifteen seasons with the agency'}, 'come from staff'))
status, body = w1.get(R)
check('The winners are listed for everyone', 'data-winner="%d"' % IDS['w2'] in body and 'data-winner="%d"' % IDS['hotels'] in body)

# ── rewards ────────────────────────────────────────────────────────────
reward = lambda **kw: {'do': 'reward_save', 'eligible': 'everyone', **kw}
check('A cash reward for everyone is refused: it is paid through payroll, so it is for workers',
      refused(rec, R, reward(name='Site bonus', kind='cash', points_cost='50', cash_amount='75'), 'for workers'))
check('a time-off reward on leave without a yearly allowance is refused',
      refused(rec, R, reward(name='Day off', kind='time_off', points_cost='40', leave_type='unpaid', leave_days='1', eligible='workers'), 'yearly allowance'))
check('and a reward costing 0 points', refused(rec, R, reward(name='Free mug', kind='merchandise', points_cost='0'), '1 to 1,000,000'))
act(rec, R, reward(name='Site bonus', kind='cash', points_cost='50', cash_amount='75', eligible='workers'))
act(rec, R, reward(name='Floating day', kind='time_off', points_cost='40', leave_type='p5reward', leave_days='1', eligible='workers'))
act(rec, R, reward(name='Fuel card', kind='gift_card', points_cost='20', stock='1'))
C = {c['name']: int(c['id']) for c in probe()['catalog']}
check('Three rewards: cash for workers, a floating day, a fuel card with one left', {'Site bonus', 'Floating day', 'Fuel card'} <= set(C))
status, body = hotels.get(R)
check('Staff are not offered rewards meant for workers', 'data-reward="%d"' % C['Fuel card'] in body and 'data-reward="%d"' % C['Site bonus'] not in body)
check('and cannot ask for them', refused(hotels, R, {'do': 'redeem', 'reward_id': C['Site bonus']}, 'for workers'))
act(w2, R, {'do': 'redeem', 'reward_id': C['Fuel card'], 'note': 'Diesel please'})
red_card = probe()['redemptions'][-1]
check('A worker asks for the fuel card: the points are held at once, and none is left', red_card['status'] == 'requested' and balance('w2') == 40
      and next(c for c in probe()['catalog'] if int(c['id']) == C['Fuel card'])['stock'] == 0)
check('Nobody else can ask for it while none is left', refused(w3, R, {'do': 'redeem', 'reward_id': C['Fuel card']}, 'None of that reward is left'))
check('A reward needing more points than the person has is refused', refused(w2, R, {'do': 'redeem', 'reward_id': C['Site bonus']}, 'needs 50 points; you have 40'))
check('Granting points to yourself is refused', refused(rec, R, {'do': 'grant', 'user_id': IDS['rec'], 'points': '100', 'reason': 'Because I can'}, 'someone else'))
check('and granting 0 points', refused(rec, R, {'do': 'grant', 'user_id': IDS['w2'], 'points': '0', 'reason': 'Safety walk'}, '1 to 10,000'))
act(rec, R, {'do': 'grant', 'user_id': IDS['w2'], 'points': '30', 'reason': 'Safety walk with the client'})
check('A recruiter grants points, with the reason', balance('w2') == 70 and probe()['points'][-1]['note'] == 'Safety walk with the client')
act(w2, R, {'do': 'redeem', 'reward_id': C['Site bonus']})
red_cash = probe()['redemptions'][-1]
check('The worker asks for the cash reward; its amount is copied from the catalogue', red_cash['kind'] == 'cash' and float(red_cash['cash_amount']) == 75.0 and balance('w2') == 20)
act(w2, R, {'do': 'cancel', 'redemption_id': red_card['id']})
check('Cancelling the fuel card gives the points and the card back', balance('w2') == 40 and next(r for r in probe()['redemptions'] if r['id'] == red_card['id'])['status'] == 'cancelled'
      and next(c for c in probe()['catalog'] if int(c['id']) == C['Fuel card'])['stock'] == 1)
check('Someone else cannot cancel a request', refused(w3, R, {'do': 'cancel', 'redemption_id': red_cash['id']}, 'not yours'))
act(rec, R, {'do': 'decide', 'redemption_id': red_cash['id'], 'decision': 'approve', 'note': ''})
check('A recruiter approves the cash reward; the worker is told', next(r for r in probe()['redemptions'] if r['id'] == red_cash['id'])['status'] == 'approved'
      and any(int(n['user_id']) == IDS['w2'] and n['message'].startswith('Approved') for n in probe()['notifications']))
check('A cash reward is not handed over under Recognition: payroll pays it', refused(rec, R, {'do': 'fulfil', 'redemption_id': red_cash['id']}, 'paid by payroll'))
act(w2, R, {'do': 'redeem', 'reward_id': C['Floating day']})
red_day = probe()['redemptions'][-1]
check('Declining without a reason is refused', refused(rec, R, {'do': 'decide', 'redemption_id': red_day['id'], 'decision': 'decline', 'note': ''}, 'Say why'))
act(rec, R, {'do': 'decide', 'redemption_id': red_day['id'], 'decision': 'decline', 'note': 'Not during the shutdown'})
check('Declined with the reason, the points are given back', next(r for r in probe()['redemptions'] if r['id'] == red_day['id'])['status'] == 'declined' and balance('w2') == 40)
before = probe('leave', CANDS['w2'], 'p5reward')
act(w2, R, {'do': 'redeem', 'reward_id': C['Floating day']})
red_day = probe()['redemptions'][-1]
act(rec, R, {'do': 'decide', 'redemption_id': red_day['id'], 'decision': 'approve', 'note': ''})
check('An approved floating day does not count until it is given', probe('leave', CANDS['w2'], 'p5reward')['allowed'] == before['allowed'])
act(rec, R, {'do': 'fulfil', 'redemption_id': red_day['id'], 'note': 'Booked with the site'})
after = probe('leave', CANDS['w2'], 'p5reward')
check('Given, it adds one day to the worker\'s leave allowance for the year', float(before['allowed']) == 2.0 and float(after['allowed']) == 3.0 and float(after['left']) == 3.0, (before, after))
act(rec2, R, {'do': 'grant', 'user_id': IDS['rec'], 'points': '20', 'reason': 'Ran the open day'})
act(rec, R, {'do': 'redeem', 'reward_id': C['Fuel card']})
check('A recruiter does not decide their own reward', refused(rec, R, {'do': 'decide', 'redemption_id': probe()['redemptions'][-1]['id'], 'decision': 'approve'}, 'Someone else decides'))

# ── a cash reward paid by payroll ──────────────────────────────────────
run = probe('run', '2099-01-03', 'open')
status, body = pay.get('/payroll-runs?id=%d' % run)
check('Payroll sees the approved cash reward on the open pay period', 'data-cash-reward="%s"' % red_cash['id'] in body)
check('and the manual adjustment form does not offer "reward"', '<option value="reward">' not in body)
check('A reward typed in by hand is refused', refused(pay, '/payroll-runs?id=%d' % run, {'do': 'adjust', 'run_id': run, 'candidate_id': CANDS['w2'], 'kind': 'reward', 'amount': '75', 'reason': 'Site bonus'}, 'not typed in here'))
rec.get(R)
check('A recruiter does not pay it', rec.post('/payroll-runs', {'do': 'pay_reward', 'run_id': run, 'redemption_id': red_cash['id']})[0] == 403)
act(pay, '/payroll-runs?id=%d' % run, {'do': 'pay_reward', 'run_id': run, 'redemption_id': red_cash['id']})
d = probe()
adj = [a for a in d['adjustments'] if int(a['run_id']) == run]
paid = next(r for r in d['redemptions'] if r['id'] == red_cash['id'])
check('Paid: one payroll adjustment of kind "reward", 75 for the worker, and the reward given with that adjustment',
      len(adj) == 1 and adj[0]['kind'] == 'reward' and float(adj[0]['amount']) == 75.0 and int(adj[0]['candidate_id']) == CANDS['w2']
      and paid['status'] == 'fulfilled' and int(paid['payroll_adjustment_id']) == int(adj[0]['id']), (adj, paid))
check('Paying it again is refused', refused(pay, '/payroll-runs?id=%d' % run, {'do': 'pay_reward', 'run_id': run, 'redemption_id': red_cash['id']}, 'only once'))
status, body = pay.get('/payroll-runs?id=%d' % run)
check('The period lists it as a reward', 'data-adjustment="%s"' % adj[0]['id'] in body and '· Reward ·' in body)
act(rec, R, {'do': 'grant', 'user_id': IDS['w1'], 'points': '50', 'reason': 'Night shift cover'})
act(w1, R, {'do': 'redeem', 'reward_id': C['Site bonus']})
act(rec, R, {'do': 'decide', 'redemption_id': probe()['redemptions'][-1]['id'], 'decision': 'approve'})
locked = probe('run', '2099-01-10', 'locked')
check('A reward is not paid into a locked period', refused(pay, '/payroll-runs?id=%d' % run, {'do': 'pay_reward', 'run_id': locked, 'redemption_id': probe()['redemptions'][-1]['id']}, 'open period'))

# ── feedback ───────────────────────────────────────────────────────────
F = '/feedback'
check('Feedback of five characters is refused', refused(w1, F, {'do': 'give', 'about_user_id': IDS['w2'], 'kind': 'praise', 'body': 'Great', 'is_shared': '1'}, '10 to 2000'))
check('and feedback about a worker on another project', refused(w1, F, {'do': 'give', 'about_user_id': IDS['wb'], 'kind': 'praise', 'body': 'Great work on the bridge deck', 'is_shared': '1'}, 'people you work with'))
check('and about yourself', refused(w1, F, {'do': 'give', 'about_user_id': IDS['w1'], 'kind': 'praise', 'body': 'I did great work today', 'is_shared': '1'}, 'someone else'))
act(w1, F, {'do': 'give', 'about_user_id': IDS['w2'], 'kind': 'praise', 'body': 'Clear hand signals during the lift', 'is_shared': '1'})
shared = probe()['feedback'][-1]['id']
act(w1, F, {'do': 'give', 'about_user_id': IDS['w2'], 'kind': 'concern', 'body': 'Skips the harness check when rushed'})
hidden = probe()['feedback'][-1]['id']
status, body = w2.get(F)
check('The person reads the feedback shared with them, not the one that was not', 'data-feedback="%s"' % shared in body and 'data-feedback="%s"' % hidden not in body)
check('and is told only of the shared one', len([n for n in probe()['notifications'] if int(n['user_id']) == IDS['w2'] and n['message'].startswith('New feedback')]) == 1)
status, body = sup.get(F + '?about=%d' % IDS['w2'])
check('Their supervisor reads both', status == 200 and 'data-feedback="%s"' % shared in body and 'data-feedback="%s" data-shared="0"' % hidden in body)
status, body = rec.get(F + '?about=%d' % IDS['w2'])
check('and so does the HR desk', 'data-feedback="%s" data-shared="0"' % hidden in body)
check('A crewmate does not', w3.get(F + '?about=%d' % IDS['w2'])[0] == 403)
check('Payroll does not', pay.get(F + '?about=%d' % IDS['w2'])[0] == 403)
act(w2, F, [('do', 'ask'), ('about_user_id', '0'), ('question', 'How did I do running the lift this week?'), ('user_ids[]', IDS['w1']), ('user_ids[]', IDS['w3'])])
reqs = [r for r in probe()['requests'] if int(r['asked_by']) == IDS['w2']]
req = int(reqs[0]['id'])
check('A worker asks two crewmates for feedback about themself; each is told', len(reqs) == 2 and all(int(r['about_user_id']) == IDS['w2'] for r in reqs)
      and all(any(int(n['user_id']) == IDS[k] and n['target'] == '/feedback?answer=%d' % req for n in probe()['notifications']) for k in ('w1', 'w3')))
check('A worker does not ask for feedback about a crewmate', refused(w1, F, [('do', 'ask'), ('about_user_id', IDS['w2']), ('question', 'How is the new crewmate doing?'), ('user_ids[]', IDS['w3'])], 'about yourself, or about someone you supervise'))
act(w1, F, {'do': 'give', 'request_id': req, 'about_user_id': IDS['w2'], 'kind': 'suggestion', 'body': 'Call the stops a bit earlier'})
act(w3, F, {'do': 'give', 'request_id': req, 'about_user_id': IDS['w2'], 'kind': 'praise', 'body': 'Calm and clear, the best lift yet', 'is_shared': '1'})
status, body = w2.get(F + '?request=%d' % req)
fb = probe()['feedback']
check('The answers come back; the one not shared stays with the HR desk and the supervisor, even from the one who asked about themself',
      'data-feedback="%s"' % fb[-1]['id'] in body and 'data-feedback="%s"' % fb[-2]['id'] not in body and 'data-asked="%d"' % IDS['w1'] in body)
check('Answering the same request twice is refused', refused(w1, F + '?answer=%d' % req, {'do': 'give', 'request_id': req, 'kind': 'praise', 'body': 'One more thing to add here'}, 'already answered'))
check('Someone it was not sent to cannot open it', wb.get(F + '?answer=%d' % req)[0] == 404)
act(sup, F, [('do', 'ask'), ('about_user_id', IDS['w2']), ('question', 'How is the lift crew lead doing?'), ('user_ids[]', IDS['w1'])])
sreq = int(probe()['requests'][-1]['id'])
check('A supervisor asks about a worker of their crew', int(probe()['requests'][-1]['about_user_id']) == IDS['w2'])
act(w1, F, {'do': 'give', 'request_id': sreq, 'kind': 'concern', 'body': 'Sometimes rushes the tag lines'})
status, body = sup.get(F + '?request=%d' % sreq)
check('The supervisor who asked reads the answer that was not shared', 'data-feedback="%s" data-shared="0"' % probe()['feedback'][-1]['id'] in body)
status, body = w2.get(F)
check('The worker it is about does not', 'data-feedback="%s"' % probe()['feedback'][-1]['id'] not in body)

# ── surveys ────────────────────────────────────────────────────────────
S = '/surveys'
survey = {'do': 'save', 'title': 'Refinery pulse', 'intro': 'Five minutes on how the job is going', 'audience': 'project', 'job_id': fx['job_a'],
          'min_group': '3', 'closes_on': day(7), 'is_anonymous': '1'}
check('A survey whose results show from 2 answers is refused', refused(rec, S, {**survey, 'min_group': '2'}, '3 to 50'))
check('and one closing today', refused(rec, S, {**survey, 'closes_on': day(0)}, 'after today'))
check('and one for a project not chosen', refused(rec, S, {**survey, 'job_id': '0'}, 'Choose a project'))
act(rec, S, survey)
SV = int(probe()['surveys'][-1]['id'])
SP = S + '?survey=%d' % SV
q = lambda kind, prompt, options=(), required='1': act(rec, SP, [('do', 'question_add'), ('survey_id', SV), ('kind', kind), ('prompt', prompt), ('is_required', required)] + [('options[]', o) for o in options])
check('A choice question with one choice is refused', q('choice', 'Which shift do you work?', ['Day', '', ''])[0] == 422)
check('and one with the same choice twice', q('choice', 'Which shift do you work?', ['Day', 'day'])[0] == 422)
q('scale', 'I have what I need to work safely')
q('enps', 'Would you recommend working with us to a friend?')
q('choice', 'Which shift do you work?', ['Day', 'Night'])
q('text', 'What one thing should we change?', required='')
Q = [int(x['id']) for x in probe()['questions'] if int(x['survey_id']) == SV]
check('Four questions are set: a scale, would-recommend, a choice and optional text', len(Q) == 4)
act(rec, S, {**survey, 'title': 'Bridge pulse', 'job_id': fx['job_b']})
small = int(probe()['surveys'][-1]['id'])
act(rec, S + '?survey=%d' % small, {'do': 'question_add', 'survey_id': small, 'kind': 'scale', 'prompt': 'I feel safe on the deck', 'is_required': '1'})
check('An anonymous survey cannot be opened for fewer people than its minimum', refused(rec, S + '?survey=%d' % small, {'do': 'open', 'survey_id': small}, 'needs at least 3 people'))
act(rec, SP, {'do': 'open', 'survey_id': SV})
inv = [i for i in probe()['invitations'] if int(i['survey_id']) == SV]
check('Opened, it asks the project\'s crew and their supervisor, each told', sorted(int(i['user_id']) for i in inv) == sorted(IDS[k] for k in ('w1', 'w2', 'w3', 'sup'))
      and all(any(int(n['user_id']) == IDS[k] and n['target'] == '/surveys?take=%d' % SV for n in probe()['notifications']) for k in ('w1', 'w2', 'w3', 'sup')))
check('An open survey is not changed', refused(rec, SP, {**survey, 'survey_id': SV, 'title': 'Renamed'}, 'only while it is a draft')
      and refused(rec, SP, {'do': 'question_add', 'survey_id': SV, 'kind': 'scale', 'prompt': 'One more question'}, 'only while it is a draft'))
status, body = w1.get(S)
check('The worker finds it under Surveys', 'data-my-survey="%d" data-answered="0"' % SV in body)
status, body = w1.get(S + '?take=%d' % SV)
check('and is told it is anonymous', 'data-anonymous="1"' in body and 'respond-form' in body)
check('A worker not asked cannot open it', wb.get(S + '?take=%d' % SV)[0] == 404)
ans = lambda scale, enps, choice, text='': [('do', 'respond'), ('survey_id', SV), ('answer[%d]' % Q[0], scale), ('answer[%d]' % Q[1], enps), ('answer[%d]' % Q[2], choice), ('answer[%d]' % Q[3], text)]
check('Leaving a required question out is refused', refused(w1, S + '?take=%d' % SV, ans('', '9', '0'), 'required'))
check('and a scale answer of 6', refused(w1, S + '?take=%d' % SV, ans('6', '9', '0'), '1 to 5'))
act(w1, S + '?take=%d' % SV, ans('5', '10', '0', 'More shade at the laydown yard'))
check('The worker answers, once', refused(w1, S + '?take=%d' % SV, ans('1', '0', '1'), 'already answered'))
status, body = rec.get(SP)
check('With one answer of a minimum of three, nothing is shown: the counts only', 'data-invited="4" data-responded="1" data-shown="0"' in body and 'id="results-held"' in body)
act(w2, S + '?take=%d' % SV, ans('4', '9', '0'))
act(w3, S + '?take=%d' % SV, ans('2', '3', '1', 'Night shift needs a second medic'))
act(rec, SP, {'do': 'remind', 'survey_id': SV})
check('A reminder goes only to the one who has not answered, and names nobody to the sender', len([n for n in probe()['notifications'] if n['message'].startswith('Reminder') and n['target'] == '/surveys?take=%d' % SV]) == 1
      and any(n['message'].startswith('Reminder') and int(n['user_id']) == IDS['sup'] for n in probe()['notifications']))
act(sup, S + '?take=%d' % SV, ans('4', '7', '0'))
status, body = rec.get(SP)
res = lambda qid: re.search(r'data-result="%d"[^>]*>' % qid, body)[0]
check('At four answers the results show', 'data-responded="4" data-shown="1"' in body)
check('The scale averages 3.75', 'data-average="3.75"' in res(Q[0]))
check('Would-recommend scores 25: two at 9-10, one at 7-8, one at 0-6', 'data-enps="25"' in res(Q[1]))
check('The choices are counted: three days, one night', 'data-choice="0" data-n="3"' in body and 'data-choice="1" data-n="1"' in body)
check('The written answers show without a name', 'More shade at the laydown yard' in body and 'Night shift needs a second medic' in body
      and 'Engagement w1' not in body.split('id="survey-results"')[1] and 'Engagement w3' not in body.split('id="survey-results"')[1])
check('One supervisor is under the minimum, so no group is broken out', 'id="segments"' not in body)
d = probe()
mine = [r for r in d['responses'] if int(r['survey_id']) == SV]
check('No answer carries a name, and their ids are random, not a sequence', len(mine) == 4 and all(r['user_id'] is None for r in mine)
      and all(re.fullmatch(r'[0-9a-f]{32}', r['id']) for r in mine))
check('Who was asked keeps only the day they answered', all(i['responded_on'] == day(0) for i in d['invitations'] if int(i['survey_id']) == SV))
act(rec, S, {'do': 'save', 'title': 'Payroll desk check-in', 'audience': 'role', 'audience_role': 'payroll', 'min_group': '3', 'closes_on': day(3)})
named = int(probe()['surveys'][-1]['id'])
act(rec, S + '?survey=%d' % named, {'do': 'question_add', 'survey_id': named, 'kind': 'text', 'prompt': 'What slows the weekly run down?', 'is_required': '1'})
act(rec, S + '?survey=%d' % named, {'do': 'open', 'survey_id': named})
status, body = pay.get(S + '?take=%d' % named)
check('A survey that is not anonymous opens for one desk, and tells them their name is kept', 'data-anonymous="0"' in body)
qid = [int(x['id']) for x in probe()['questions'] if int(x['survey_id']) == named][0]
act(pay, S + '?take=%d' % named, {'do': 'respond', 'survey_id': named, 'answer[%d]' % qid: 'Late timesheets from the bridge'})
status, body = rec.get(S + '?survey=%d' % named)
check('Its results show at one answer, with the name', 'data-shown="1"' in body and 'Late timesheets from the bridge' in body and 'Engagement pay' in body
      and int(next(r for r in probe()['responses'] if int(r['survey_id']) == named)['user_id']) == IDS['pay'])
probe('closes', named, day(-1))
out = subprocess.run([php, 'work/test-app/install/run-workflows.php'], capture_output=True, text=True)
check('The scheduled workflows close a survey whose date has passed', out.returncode == 0 and next(s for s in probe()['surveys'] if int(s['id']) == named)['status'] == 'closed', out.stdout + out.stderr)
act(rec, SP, {'do': 'close', 'survey_id': SV})
check('A survey closed by hand takes no more answers', next(s for s in probe()['surveys'] if int(s['id']) == SV)['status'] == 'closed'
      and refused(sup, S + '?take=%d' % SV, ans('1', '1', '1'), 'closed'))

act(rec, S, {'do': 'save', 'title': 'Agency pulse', 'audience': 'everyone', 'min_group': '3', 'closes_on': day(5), 'is_anonymous': '1'})
ag = int(probe()['surveys'][-1]['id'])
act(rec, S + '?survey=%d' % ag, {'do': 'question_add', 'survey_id': ag, 'kind': 'scale', 'prompt': 'I would stay another year', 'is_required': '1'})
act(rec, S + '?survey=%d' % ag, {'do': 'open', 'survey_id': ag})
aq = [int(x['id']) for x in probe()['questions'] if int(x['survey_id']) == ag][0]
for c, v in [(rec, 5), (rec2, 4), (pay, 3), (hotels, 4), (w1, 2), (w2, 3), (w3, 1)]:
    act(c, S + '?take=%d' % ag, {'do': 'respond', 'survey_id': ag, 'answer[%d]' % aq: str(v)})
status, body = rec.get(S + '?survey=%d' % ag)
check('With enough answers in two groups, each is broken out: staff average 4, workers 2; supervisors, with none, are not',
      'data-segment="staff"' in body and 'data-segment="workers"' in body and 'data-segment="supervisors"' not in body
      and 'data-by-segment="staff">Staff: 4<' in body and 'data-by-segment="workers">Workers: 2<' in body, body.split('id="survey-results"')[-1][:1500])

# ── who may see what ───────────────────────────────────────────────────
check('Logistics does not write surveys', act(hotels, S, {**survey, 'title': 'Mine'})[0] == 403)
check('A worker does not read results', w1.get(SP)[0] == 403)
check('A supervisor does not decide rewards', act(sup, R, {'do': 'decide', 'redemption_id': red_day['id'], 'decision': 'approve'})[0] == 403)
check('Payroll does not run awards', act(pay, R, {'do': 'award', 'nomination_id': w1nom['id']})[0] == 403)

# ── translations ───────────────────────────────────────────────────────
status, body = rec.get(R + '?lang=fr')
check('Recognition in French', 'Remerciements' in body and 'Récompenses' in body)
status, body = w1.get(S + '?lang=es')
check('Surveys in Spanish', 'Encuestas' in body)
status, body = w2.get(F + '?lang=fr')
check('Feedback in French', 'Retours' in body)
rec.get(R + '?lang=en')
w1.get(S + '?lang=en')
w2.get(F + '?lang=en')

json.dump(results, open('work/en-http-results.json', 'w'), indent=1)
