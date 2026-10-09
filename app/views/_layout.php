<?php
/**
 * The shell every screen sits in.
 *
 * The navigation shows a desk only to the people who work it, so a recruiter
 * opening this at 7am sees Candidates and the roster, not payroll.
 */
$u = user();
$f = flash();

require_once __DIR__ . '/../navigation.php';
require_once __DIR__ . '/../activity.php';
$nav      = workspace_nav($u);
$sections = workspace_sections();
$navSections=[];$navOrder=[];$position=0;
foreach($sections as $section=>$links) foreach($links as $link){$navSections[$link]=$section;$navOrder[$link]=$position++;}
usort($nav,fn($left,$right)=>($navOrder[$left[0]] ?? 999)<=>($navOrder[$right[0]] ?? 999));
if(!$u)$nav=[];
$here = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '', '/');
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? $appName) ?></title>
<link rel="manifest" href="/app-manifest">
<link rel="apple-touch-icon" href="/assets/app-icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#0D2137">
<link rel="icon" href="/assets/app-icon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
<style>
:root{
  --navy:#0F2E4C; --navy-2:#17456F; --blue:#346EB6; --blue-soft:#8CBBE5;
  --ink:#16202B; --muted:#657387; --line:#DFE5EC; --bg:#F4F6F9; --card:#fff;
  --green:#1C7C4A; --amber:#B4740B; --red:#B3261E; --grey:#7A8798;
  --radius:10px;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);
  font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
a{color:var(--blue);text-decoration:none} a:hover{text-decoration:underline}

/* ── frame ── */
.top{background:var(--navy);color:#fff;padding:0 20px;display:flex;align-items:center;gap:22px;
  height:56px;position:sticky;top:0;z-index:20}
.brand{font-weight:700;letter-spacing:.3px;font-size:17px;color:#fff;white-space:nowrap}
.brand span{color:var(--blue-soft);font-weight:400}
.nav{display:flex;gap:2px;flex:1;overflow-x:auto}
.nav a{color:#CBD8E6;padding:8px 13px;border-radius:7px;white-space:nowrap;font-size:14px}
.nav a:hover{background:var(--navy-2);color:#fff;text-decoration:none}
.nav a.on{background:var(--blue);color:#fff;font-weight:600}
.me{display:flex;align-items:center;gap:10px;font-size:13px;color:#CBD8E6;white-space:nowrap}
.me b{color:#fff;font-weight:600}
.me a{color:var(--blue-soft)}

.wrap{max-width:1240px;margin:0 auto;padding:22px 20px 60px}
h1{font-size:22px;margin:0 0 3px} h2{font-size:16px;margin:0 0 12px}
.sub{color:var(--muted);font-size:13px;margin:0 0 20px}

/* ── pieces ── */
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
  padding:16px 18px;margin-bottom:16px}
.card.tight{padding:0;overflow:hidden}
.grid{display:grid;gap:14px}
.g2{grid-template-columns:repeat(auto-fit,minmax(280px,1fr))}
.g4{grid-template-columns:repeat(auto-fit,minmax(148px,1fr))}
.stat{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px}
.stat .n{font-size:27px;font-weight:700;line-height:1.1;font-variant-numeric:tabular-nums}
.stat .l{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.6px;margin-top:3px}
.stat .h{font-size:12px;color:var(--muted);margin-top:6px}

table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);
  padding:10px 12px;border-bottom:1px solid var(--line);background:#FAFBFD;white-space:nowrap}
td{padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:middle}
tr:last-child td{border-bottom:0}
tbody tr:hover{background:#FAFBFD}
.num{text-align:right;font-variant-numeric:tabular-nums}
.scroll{overflow-x:auto}

/* A table with controls in it cannot be squeezed into the card: the
   inputs collapse to a few pixels and read as "Note (" and "Rec".
   A wide table keeps its width and .scroll scrolls it instead. */
.scroll table.wide{min-width:1120px}
td.nowrap,th.nowrap{white-space:nowrap}

.call-log{display:inline-flex;align-items:center;gap:6px;justify-content:flex-end}
.call-log input{width:180px;min-height:34px;padding:5px 10px;font-size:13px;margin:0}
.call-log select{width:auto;min-width:142px;margin:0}
.stage-move{display:inline-flex;gap:6px;justify-content:flex-end}
.stage-move select{margin:0}

.table-footer{display:flex;flex-wrap:wrap;align-items:center;gap:10px;
  justify-content:space-between;padding:10px 14px;margin:0;border-top:1px solid var(--line)}
.table-footer .legend{display:flex;flex-wrap:wrap;gap:6px}

/* Opening a person is a recruiter about to contact them: the number
   and the address come first, the record of what happened second. */
.contact-head{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start;
  justify-content:space-between;margin-bottom:14px}
.contact-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.contact-log{align-items:flex-end;gap:10px}
.contact-log .btn{white-space:nowrap}

/* Checkboxes that wrap instead of being crushed onto one line, and a
   tick label that does not need its styling written inline. */
.pool-filters{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center}
.pool-filters .own-room{margin:0}
.tick{display:flex;align-items:center;gap:7px;margin:0;font-weight:400;color:var(--ink)}
.tick input{width:auto;margin:0}

/* A project is several trades at several rates. The split says which
   line is short, which one total never could. */
.trade-split{list-style:none;margin:8px 0 0;padding:0;font-size:12px;max-width:190px}
.trade-split li{display:flex;justify-content:space-between;gap:10px;padding:1px 0}
.trade-split .trade-name{color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.trade-split .short{color:var(--amber);font-weight:600}

/* Buttons and tags beside a heading, wrapping rather than overflowing. */
.heading-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.check-row{align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.check-row .check-title{flex:1;min-width:160px}
.check-row input[name="note"]{max-width:260px}

/* A block has to be impossible to walk past, so it is the first thing
   on the person and it does not look like the rest of the page. */
.do-not-use{background:var(--red);border-color:var(--red);color:#fff}
.do-not-use h2{color:#fff}
.do-not-use a{color:#fff;text-decoration:underline}

.skill-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:7px}
.skill-list li{display:flex;align-items:center;gap:9px;flex-wrap:wrap}

/* A barred name reads as barred before the tag beside it is read. */
.barred{color:var(--red);font-weight:600}

/* One card per assignment waiting to be graded. */
.grade-form{padding:16px 18px;border-bottom:1px solid var(--line)}
.grade-form:last-child{border-bottom:0}

.tag{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:600;
  border:1px solid;white-space:nowrap}
.tag.blue {color:#1B4F8A;border-color:#BBD4EE;background:#EDF4FC}
.tag.green{color:var(--green);border-color:#B6DEC6;background:#EDF8F1}
.tag.amber{color:var(--amber);border-color:#EEDCB0;background:#FDF6E7}
.tag.red  {color:var(--red);border-color:#F0C4C1;background:#FDEEED}
.tag.grey {color:var(--grey);border-color:var(--line);background:#F4F6F9}

.btn{display:inline-block;background:var(--blue);color:#fff;border:1px solid var(--blue);
  padding:7px 14px;border-radius:7px;font-size:14px;cursor:pointer;font-family:inherit}
.btn:hover{background:#2B5C99;text-decoration:none}
.btn.ghost{background:#fff;color:var(--navy);border-color:var(--line)}
.btn.ghost:hover{background:#F4F6F9}
.btn.sm{padding:4px 10px;font-size:13px}

input,select,textarea{font:inherit;padding:7px 10px;border:1px solid var(--line);
  border-radius:7px;background:#fff;color:var(--ink);width:100%}
input:focus,select:focus,textarea:focus{outline:2px solid var(--blue-soft);outline-offset:-1px;border-color:var(--blue)}
label{display:block;font-size:12px;color:var(--muted);margin:0 0 4px;font-weight:600}
.field{margin-bottom:12px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.row>*{flex:1;min-width:130px}
.row.tight>*{flex:0 0 auto;min-width:0}

.flash{padding:11px 15px;border-radius:var(--radius);margin-bottom:16px;font-size:14px;border:1px solid}
.flash.ok{background:#EDF8F1;border-color:#B6DEC6;color:#14603A}
.flash.err{background:#FDEEED;border-color:#F0C4C1;color:#8E1D17}

.empty{padding:34px 20px;text-align:center;color:var(--muted);font-size:14px}
/* What stands between a placement and the site - read, not clicked through. */
.blocker-list{margin:0;padding-left:16px;color:var(--amber);font-size:12.5px;line-height:1.5}
.blocker-list li{margin:0 0 2px}
.blocker-list a{color:var(--amber);text-decoration:underline}
/* Booking a room: one line of real fields, wrapping on a narrow screen. */
.booking-form{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;justify-content:flex-end}
.booking-form>div{min-width:0}
.booking-form label{margin:0 0 4px;font-size:10.5px;text-transform:uppercase;letter-spacing:.6px}
.booking-form input,.booking-form select{min-height:36px;padding:6px 10px;font-size:13px}
.booking-form select{padding-right:30px}
.booking-form input[type=date]{max-width:150px}
.booking-form .own-room{display:flex;align-items:center;gap:7px;margin:0 0 4px;
  font-size:13px;font-weight:500;color:var(--ink);text-transform:none;letter-spacing:0;white-space:nowrap}
.booking-form .own-room input{min-height:0;margin:0}
.booking-form button{min-height:36px;padding:7px 18px}

.queue-label{display:flex;flex-direction:column;gap:2px}
.queue-source{font-size:12px;color:var(--muted);line-height:1.4}

/* The questionnaire builder. */
.questions td{vertical-align:top;padding-top:11px;padding-bottom:11px}
.question-actions{display:flex;gap:5px;justify-content:flex-end}
.question-actions form{margin:0}
.question-actions .btn{min-height:30px;padding:4px 10px;font-size:12px}

/* The public board: one card per opening, the terms up front. */
.opening-terms{display:flex;flex-wrap:wrap;gap:10px 18px;padding:12px 14px;margin:0 0 14px;
  background:#F3F7FD;border-radius:9px;font-size:13.5px}
.opening-terms strong{font-size:15px}
.opening-text{white-space:pre-wrap;line-height:1.6}
.opening h3{margin:16px 0 8px;font-size:12px;text-transform:uppercase;
  letter-spacing:.7px;color:var(--muted)}

/* One person: where they are, and what is missing. */
.journey{list-style:none;display:flex;flex-wrap:wrap;gap:0;margin:0;padding:0}
.journey li{flex:1;min-width:118px;display:flex;flex-direction:column;gap:5px;
  padding:0 10px 10px 0;position:relative}
.journey li::before{content:"";position:absolute;left:10px;right:0;top:9px;height:2px;background:#E3E9F1}
.journey li:last-child::before{display:none}
.journey-mark{width:20px;height:20px;border-radius:50%;background:#E3E9F1;color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;
  position:relative;z-index:1;flex:0 0 auto}
.journey li.done .journey-mark{background:var(--green)}
.journey li.done::before{background:var(--green)}
.journey li.todo .journey-mark{background:#fff;border:2px dashed #C3CEDC}
.journey-body strong{display:block;font-size:13px}
.journey li.todo .journey-body strong{color:var(--muted)}
.next-step{margin:14px 0 0;padding:11px 14px;border-radius:9px;background:#FFF6E6;font-size:14px}
.next-step.clear{background:#F1FAF4}

/* An application, its checks and its answers, on the person. */
.application,.assignment{border:1px solid var(--line);border-radius:var(--radius);
  padding:14px 16px;margin-bottom:14px}
.application:last-child,.assignment:last-child{margin-bottom:0}
.application-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.application h4,.assignment h4{margin:16px 0 8px;font-size:12px;text-transform:uppercase;
  letter-spacing:.7px;color:var(--muted)}
.checks{list-style:none;margin:0;padding:0}
.checks li{display:flex;align-items:center;gap:10px;padding:6px 0;
  border-bottom:1px solid rgba(0,0,0,.05)}
.checks li:last-child{border-bottom:0}
.check-title{flex:1;font-size:13.5px}
.inline-form{margin:0}
.inline-form select{min-height:32px;padding:4px 26px 4px 9px;font-size:12.5px;width:auto}
.answers{list-style:none;margin:0;padding:0;font-size:13.5px}
.answers li{display:flex;flex-direction:column;gap:1px;padding:6px 0;
  border-bottom:1px solid rgba(0,0,0,.05)}
.answers li:last-child{border-bottom:0}
.plain{list-style:none;margin:0 0 6px;padding:0;font-size:13.5px}
.plain li{padding:6px 0;border-bottom:1px solid var(--line)}
.plain li:last-child{border-bottom:0}
.calls li{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.calls li>div{flex:0 0 100%}

/* The org chart: a person, where they stand, and who carries them. */
.org-crew{list-style:none;margin:0;padding:0}
.org-crew li{display:flex;align-items:center;gap:14px;flex-wrap:wrap;
  padding:11px 0;border-bottom:1px solid rgba(0,0,0,.07)}
.org-crew li:last-child{border-bottom:0}
.org-person{flex:1;min-width:170px;display:flex;flex-direction:column;gap:1px}
.org-side{white-space:nowrap}
.assign-form{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.assign-form select,.assign-form input{min-height:34px;padding:5px 10px;font-size:13px;width:auto}
.assign-form select{max-width:170px;padding-right:28px}
.assign-form input{max-width:120px}
.assign-form .btn{min-height:34px;padding:6px 14px}

/* The portfolio: one row per project, read across. */
.portfolio td{vertical-align:top;padding-top:13px;padding-bottom:13px}
.portfolio .blocker-list{margin:0;padding-left:15px}
.portfolio tr.muted-row td{opacity:.55}

/* Where a project is in its life. */
.lifecycle-bar{list-style:none;display:flex;gap:0;margin:0;padding:0;flex-wrap:wrap}
.lifecycle-bar li{flex:1 1 0;min-width:96px;position:relative;padding:0 10px 14px 0}
@media(max-width:820px){.lifecycle-bar{overflow-x:auto;flex-wrap:nowrap}
  .lifecycle-bar li{flex:0 0 150px}}
.lifecycle-bar strong{display:block;font-size:13.5px;color:#9AA8B8;margin-top:9px}
.lifecycle-bar .muted{display:block;line-height:1.4;color:#AEB9C7}
.lifecycle-bar .dot{display:block;width:13px;height:13px;border-radius:50%;
  background:#DFE5EC;border:2px solid #DFE5EC;position:relative;z-index:1}
.lifecycle-bar li::before{content:"";position:absolute;left:6px;right:0;top:6px;
  height:2px;background:#DFE5EC}
.lifecycle-bar li:last-child::before{display:none}
.lifecycle-bar li.done .dot{background:var(--blue);border-color:var(--blue)}
.lifecycle-bar li.done::before{background:var(--blue)}
.lifecycle-bar li.done strong{color:#54637A}
.lifecycle-bar li.now .dot{background:#fff;border-color:var(--blue);
  box-shadow:0 0 0 4px rgba(52,110,182,.18)}
.lifecycle-bar li.now strong{color:var(--navy);font-weight:700}
.lifecycle-bar li.now .muted{color:var(--muted)}

/* The gate: what has to be true before the project moves on. */
.gate{border-color:#F3DCC0;background:#FFFBF4}
.gate.ready{border-color:#BFE3CD;background:#F4FBF7}
.signals{list-style:none;margin:0;padding:0}
.signals li{display:flex;align-items:center;gap:13px;padding:11px 0;
  border-bottom:1px solid rgba(0,0,0,.06)}
.signals li:last-child{border-bottom:0}
.signal-mark{flex:0 0 24px;height:24px;border-radius:50%;display:flex;
  align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff}
.signals li.met .signal-mark{background:var(--green)}
.signals li.unmet .signal-mark{background:var(--amber)}
.signal-body{flex:1;min-width:0}
.signal-body strong{display:block;font-size:14px}

/* Exceptions somebody has to clear, and who is carrying the crew. */
.exceptions,.supervisors{list-style:none;margin:0;padding:0}
.exceptions li{padding:7px 0;border-bottom:1px solid var(--line);font-size:13.5px}
.exceptions li:last-child{border-bottom:0}
.exceptions li.good a{color:var(--green)}
.exceptions li.bad a{color:var(--red);font-weight:600}
.supervisors li{display:flex;justify-content:space-between;align-items:center;
  gap:12px;padding:7px 0;border-bottom:1px solid var(--line)}
.supervisors li:last-child{border-bottom:0}

/* Search results: what it is on the left, where it stands on the right. */
.results{list-style:none;margin:0;padding:0}
.results li{border-bottom:1px solid var(--line)}
.results li:last-child{border-bottom:0}
.results a{display:flex;align-items:center;justify-content:space-between;gap:16px;
  padding:12px 18px;color:var(--ink);flex-wrap:wrap}
.results a:hover{background:#F6F9FF;text-decoration:none}
.result-main{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1}
.result-side{display:flex;align-items:center;gap:9px;white-space:nowrap}

/* A decision, with room for the reason. */
.decision{display:flex;gap:18px;align-items:flex-start;justify-content:space-between;
  flex-wrap:wrap;padding:15px 18px;border-bottom:1px solid var(--line)}
.decision:last-child{border-bottom:0}
.decision-what{flex:1;min-width:240px}
.decision-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.decision-form input{min-height:38px;padding:7px 11px;font-size:13.5px;width:210px}
.decision-form .btn{min-height:38px;padding:8px 16px}

/* A chain, read top to bottom. */
.chain{list-style:none;margin:0;padding:0;counter-reset:none}
.chain li{display:flex;align-items:center;gap:14px;padding:11px 0;
  border-bottom:1px solid var(--line)}
.chain li:last-child{border-bottom:0}
.chain-order{flex:0 0 28px;height:28px;border-radius:50%;background:var(--blue);color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:12.5px;font-weight:700}
.chain-body{flex:1}
.chain-body strong{display:block}

/* How many things are waiting, on the menu entry that opens them. */
.nav-badge{display:inline-block;min-width:19px;padding:1px 6px;margin-left:7px;
  border-radius:999px;background:var(--red);color:#fff;font-size:11px;font-weight:700;
  line-height:17px;text-align:center}
.nav a.on .nav-badge{background:#fff;color:var(--blue)}

/* The queue itself: one line per kind of work, the count first. */
.queue{list-style:none;margin:0;padding:0}
.queue li{border-bottom:1px solid var(--line)}
.queue li:last-child{border-bottom:0}
.queue a{display:flex;align-items:center;gap:14px;padding:14px 18px;color:var(--ink)}
.queue a:hover{background:#F6F9FF;text-decoration:none}
.queue-count{min-width:38px;text-align:center;font-size:13px;font-weight:700}
.queue-label{flex:1}
.queue-go{color:var(--muted)}

/* The headcount countdown. */
.headcount-top{display:flex;align-items:flex-start;justify-content:space-between;
  gap:16px;flex-wrap:wrap}
.headcount-figure{text-align:right}
.headcount-figure strong{display:block;font-size:34px;line-height:1;
  font-variant-numeric:tabular-nums}
.headcount-figure span{font-size:11px;text-transform:uppercase;letter-spacing:.7px;
  color:var(--muted)}
.headcount-bar{height:9px;border-radius:999px;background:#E7ECF3;overflow:hidden;margin-top:16px}
.headcount-bar span{display:block;height:100%;border-radius:999px;background:var(--blue)}

/* What has come in. */
.notice-list{list-style:none;margin:0;padding:0}
.notice-list li{display:flex;justify-content:space-between;gap:14px;padding:9px 0;
  border-bottom:1px solid var(--line)}
.notice-list li:last-child{border-bottom:0}
.notice-list li.unread a{font-weight:600}

/* The roster reads top to bottom per person, not across ten columns. */
.roster-table td{vertical-align:top;padding-top:12px;padding-bottom:12px}
.roster-table td>div{margin-top:3px}
.roster-table .tag{margin-right:4px}
.move-select{width:auto;min-height:34px;padding:5px 30px 5px 10px;font-size:13px}

/* One slim line above the page: what you are working on, what is waiting. */
.workbar{display:flex;align-items:center;gap:16px;flex-wrap:wrap;
  margin:0 0 20px;padding:0 0 14px;border-bottom:1px solid var(--line)}
.workbar-project{display:flex;align-items:center;gap:10px;margin:0;flex:1;min-width:260px}
.workbar-project label{margin:0;white-space:nowrap;font-size:11px;text-transform:uppercase;
  letter-spacing:.7px;color:var(--muted)}
.workbar-project select{min-height:36px;padding:6px 32px 6px 11px;font-size:13.5px;
  max-width:380px;background-color:var(--card)}
.workbar-unread{display:inline-flex;align-items:center;gap:7px;padding:6px 13px;
  border-radius:999px;background:#FFF4E0;color:#8A5A04;font-size:12.5px;font-weight:600}
.workbar-unread::before{content:'';width:7px;height:7px;border-radius:50%;background:var(--amber)}
.workbar-unread:hover{text-decoration:none;background:#FBE8C8}
@media(max-width:620px){.workbar-project{min-width:0;width:100%}
  .workbar-project select{max-width:none;flex:1}}

/* Settings: section jumps, the permission grid, and what a desk opens. */
.settings-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 18px}
.settings-tabs a{padding:7px 14px;border:1px solid var(--line);border-radius:999px;
  background:var(--card);font-size:13px;font-weight:600;color:#44546B}
.settings-tabs a:hover{border-color:var(--blue);color:var(--blue);text-decoration:none}
.permission-grid th[scope="row"]{text-align:left;text-transform:none;font-size:14px;
  color:var(--ink);letter-spacing:0;white-space:nowrap}
.permission-grid th .muted{display:block;font-weight:400;letter-spacing:0;text-transform:none}
.permission-grid td.center,.permission-grid th.center{text-align:center}
.permission-grid input[type=checkbox]{width:19px;height:19px;accent-color:var(--blue);cursor:pointer}
.desk-card{border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px}
.desk-card strong{display:block;font-size:14px}
.desk-card ul{margin:10px 0 0;padding-left:16px;color:#54637A;font-size:12.5px;line-height:1.65}
.muted{color:var(--muted)}
.small{font-size:12.5px}
.right{text-align:right}
.mono{font-variant-numeric:tabular-nums}

@media (max-width:720px){
  .top{gap:12px;padding:0 12px} .brand{font-size:15px} .me b{display:none}
  .wrap{padding:16px 12px 50px}
}

/* Fleury Solutions workspace: persistent navigation and readable operations. */
:root{--navy:#10263b;--blue:#3166db;--bg:#f3f6fb;--line:#e3e9f1;--radius:14px}
.top{height:68px;box-shadow:0 2px 14px #10263b12;gap:18px}
.brand{font-size:20px;min-width:204px}
.top .nav{position:fixed;left:0;top:68px;bottom:0;width:228px;display:flex;flex-direction:column;gap:3px;padding:20px 14px;background:white;border-right:1px solid var(--line);overflow-y:auto}
.nav a{color:#526176;padding:10px 13px;font-size:13px;letter-spacing:.1px}
.nav a:hover{background:#eef3ff;color:var(--blue)}
.nav a.on{background:#e9f0ff;color:#2455bf;font-weight:700}
/* A folded section: the heading is the control, and it says so. */
.nav-group{border-bottom:1px solid #f0f3f8}
.nav-group:last-of-type{border-bottom:0}
.nav-group>summary{list-style:none;cursor:pointer;display:flex;align-items:center;
  justify-content:space-between;padding:10px 12px;margin:2px 0;border-radius:7px;
  font-size:10.5px;font-weight:700;letter-spacing:.9px;text-transform:uppercase;
  color:var(--muted);user-select:none}
.nav-group>summary::-webkit-details-marker{display:none}
.nav-group>summary:hover{background:#f3f6fb;color:var(--navy)}
.nav-group>summary:focus-visible{outline:2px solid var(--blue);outline-offset:1px}
.nav-group>summary::after{content:'';width:6px;height:6px;flex:0 0 auto;margin-left:8px;
  border-right:1.5px solid currentColor;border-bottom:1.5px solid currentColor;
  transform:rotate(45deg);transition:transform .15s}
/* Openness is shown by the chevron, not by a colour: this sidebar is dark
   under the brand stylesheet and light without it, and a single colour
   cannot be legible on both. */
.nav-group[open]>summary{opacity:1}
.nav-group[open]>summary::after{transform:rotate(-135deg)}
.nav-group>a{display:block;margin-left:6px}
@media(max-width:720px){.nav-group{border:0}.nav-group>summary{color:#9fb4cc}
  .nav-group[open]>summary{color:#fff}}
.me{margin-left:auto}.wrap{margin-left:228px;max-width:1600px;padding:28px 30px 70px}
h1{font-size:29px;letter-spacing:-.6px;margin-bottom:7px}h2{font-size:17px}
.card,.stat{box-shadow:0 3px 12px #18334d05}.stat{padding:22px}.stat .n{font-size:35px;margin-top:10px}.stat .l{font-size:11px;letter-spacing:.9px}
.btn{font-weight:600;padding:9px 16px}.card{padding:22px}.card form.row{padding:10px 0;border-bottom:1px solid var(--line)}
.field{margin-bottom:16px}label{margin-top:8px}input,textarea,select{margin-bottom:8px}textarea{min-height:94px}
article{padding:14px 0;border-bottom:1px solid var(--line)}
.product-note{color:#acbfd3;font-size:11px;letter-spacing:.5px}
@media(max-width:1000px){.top .nav{width:190px}.brand{min-width:160px}.wrap{margin-left:190px;padding:22px}}
@media(max-width:720px){.top{height:auto;min-height:64px;flex-wrap:wrap;padding:12px}.top .nav{position:static;width:100%;flex:0 0 100%;flex-direction:row;order:3;padding:5px 0;border:0;background:transparent;overflow-x:auto}.nav a{color:#d4dfec;padding:8px 12px}.nav a.on{background:#3166db;color:white}.wrap{margin-left:0;padding:18px 12px}.brand{font-size:18px;min-width:0}.me{font-size:11px}.me span{display:none}h1{font-size:24px}.card{padding:16px}}
.lang-switch{display:inline-flex;align-items:center}
.me-who{display:inline-flex;flex-direction:column;line-height:1.15;margin-right:4px}
.me-who small{opacity:.72;font-size:11.5px}

/* ── Forms ──────────────────────────────────────────────────────────────
   Applied last on purpose: this block is the house style and overrides the
   earlier rules rather than replacing them, so nothing already written
   needs touching. */

label{
  display:block;font-size:12.5px;font-weight:600;letter-spacing:.1px;
  color:#2B3A4D;margin:0 0 6px;
}

input,select,textarea{
  font:inherit;font-size:14.5px;width:100%;
  padding:11px 13px;min-height:44px;
  border:1px solid #D4DCE6;border-radius:9px;background:#fff;color:var(--ink);
  transition:border-color .12s ease, box-shadow .12s ease;
  margin-bottom:0;
}
textarea{min-height:110px;line-height:1.5;padding-top:10px}

input::placeholder,textarea::placeholder{color:#9AA8B8}

input:hover,select:hover,textarea:hover{border-color:#B9C6D6}

input:focus,select:focus,textarea:focus{
  outline:none;border-color:var(--blue);
  box-shadow:0 0 0 3px rgba(52,110,182,.16);
}

input:disabled,select:disabled,textarea:disabled{background:#F4F6F9;color:#8795A8;cursor:not-allowed}

/* A date or number field has no business filling the width of a screen. */
input[type="date"],input[type="time"]{max-width:200px}
input[type="number"]{max-width:220px}
input[type="checkbox"],input[type="radio"]{
  width:auto;min-height:0;margin:0 7px 0 0;accent-color:var(--blue);
  transform:scale(1.15);vertical-align:middle;
}

select{
  appearance:none;
  background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath fill='%23657387' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 13px center;padding-right:34px;
}

.field{margin-bottom:18px}
.field:last-child{margin-bottom:0}

/* The same rhythm without a wrapper, so a screen written as bare
   label/control pairs still reads as a form rather than a stack. */
.card label{margin-top:18px}
.card label:first-of-type,.card h1+label,.card h2+label,.card h3+label,
.card .eyebrow+label,.field label,.row label{margin-top:0}

/* The other shape used here: the control lives inside its own label. */
label>input,label>select,label>textarea{margin-top:6px;font-weight:400}

/* A label that is only a tickbox and its words sits on one line. */
label:has(>input[type=checkbox]),label:has(>input[type=radio]){
  display:flex;align-items:center;gap:9px;font-weight:500;font-size:14px;
  color:var(--ink);margin-top:16px;cursor:pointer}
label:has(>input[type=checkbox])>input,label:has(>input[type=radio])>input{margin-top:0}

/* A submit button needs air between it and the last field. */
.card>form>button.btn,.card>button.btn,form.card>button.btn{margin-top:22px}
.row>button.btn{margin-top:0}

/* Help text under a field, for the sentence that stops somebody guessing. */
.hint,.form-text{display:block;margin-top:6px;font-size:12px;color:var(--muted);line-height:1.45}

/* Rows breathe, and a row inside a card is not a table row. */
.row{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end}
.card form.row{padding:16px 0;border-bottom:1px solid var(--line)}
.card form.row:last-child{border-bottom:0}

/* Forms get a heading that separates them from what came before. */
.card h2{
  font-size:15px;font-weight:700;letter-spacing:.2px;color:var(--navy);
  margin:0 0 4px;padding-bottom:0;
}
.card h2 + .sub,.card h2 + .muted{margin-top:0;margin-bottom:16px}

/* ── Buttons ───────────────────────────────────────────────────────── */
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:7px;
  min-height:44px;padding:11px 20px;font-size:14.5px;font-weight:600;
  border-radius:9px;border:1px solid transparent;cursor:pointer;
  background:var(--blue);color:#fff;
  box-shadow:0 1px 2px rgba(16,40,70,.10);
  transition:background .12s ease, box-shadow .12s ease, transform .06s ease;
}
.btn:hover{background:#2B5C99;text-decoration:none;box-shadow:0 2px 8px rgba(16,40,70,.16)}
.btn:active{transform:translateY(1px)}
.btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(52,110,182,.32)}

.btn.ghost{background:#fff;color:var(--navy);border-color:#D4DCE6;box-shadow:none;font-weight:600}
.btn.ghost:hover{background:#F4F7FB;border-color:#B9C6D6}

.btn.sm{min-height:34px;padding:7px 13px;font-size:13px;border-radius:7px}

/* A form's last line is its action: give it room and a line above it. */
.card > form > .btn:last-child,
.card > form > button:last-child{margin-top:4px}

/* ── Tables inside cards ───────────────────────────────────────────── */
th{font-size:11px;letter-spacing:.7px;padding:12px 14px;color:#5B6B80;background:#FAFBFD}
td{padding:13px 14px;font-size:14px}
tbody tr:hover{background:#F8FAFD}

/* ── Empty states deserve the same care as full ones ───────────────── */
.empty{padding:40px 24px;text-align:center;color:var(--muted);font-size:14.5px;line-height:1.6}
.empty .btn{margin-top:4px}

@media(max-width:720px){
  input,select,textarea,.btn{font-size:16px}  /* stops iOS zooming on focus */
  .row{gap:12px}
  input[type="date"],input[type="time"],input[type="number"]{max-width:100%}
}

.lang-switch select{width:auto;padding:4px 8px;font-size:12.5px}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
</style>
<link rel="stylesheet" href="/assets/fleury-brand.css?v=20261008">
</head>
<body>

<header class="top">
  <div><div class="brand"><?= e($appName) ?></div><div class="product-note"><?= te('A Fleury Solutions platform') ?></div></div>
  <nav class="nav" aria-label="<?= te('Workspace navigation') ?>"><div class="sidebar-identity"><div class="sidebar-symbol">C</div><strong><?= e($appName) ?></strong><span><?= te('by Fleury Solutions') ?></span></div>
    <?php
    // Everything the sidebar needs is local to this call, so nothing it
    // uses can still be set when the view runs below.
    (function (array $nav, array $navSections, string $here): void {
        $grouped = [];

        foreach ($nav as [$href, $label, $need]) {
            if ($need !== null && ! can(...(array) $need)) { continue; }
            $grouped[$navSections[$href] ?? 'Workspace'][] = [$href, $label];
        }

        foreach ($grouped as $title => $links) {
            $holdsPage = (bool) array_filter($links, fn ($l) => $l[0] === $here);

            printf('<details class="nav-group" data-section="%s"%s><summary>%s</summary>',
                   e($title), $holdsPage ? ' open' : '', te($title));

            foreach ($links as [$href, $label]) {
                // The queue count rides on the Activity entry, so the
                // number is visible from whatever screen somebody is on.
                $badge = $href === '/activity' && ($n = activity_total()) > 0
                    ? '<span class="nav-badge">' . (int) $n . '</span>'
                    : '';

                printf('<a href="%s" class="%s">%s%s</a>',
                       e($href), $here === $href ? 'on' : '', te($label), $badge);
            }

            echo '</details>';
        }
    })($nav, $navSections, $here);
    ?>  </nav>
  <?php if($u && !in_array($u['role'] ?? '',['worker','client'],true)): ?><form action="/search" method="get" class="workspace-search"><label class="sr-only" for="directory-search"><?= te('Search employee folders') ?></label><input id="directory-search" name="q" placeholder="<?= te('Search everything') ?>" aria-label="<?= te('Search everything') ?>"><button class="btn ghost sm"><?= te('Search') ?></button></form><?php endif; ?>
  <div class="me">
    <?php /* Name first, then what this account may do, on its own line. The
             two used to sit side by side and read as one meaningless phrase. */ ?>
    <span class="me-who">
      <b><?= e($u['name'] ?? '') ?></b>
      <small><?= te(roles()[$u['role'] ?? ''] ?? '') ?></small>
    </span>
    <form method="post" action="/language" class="lang-switch">
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? $here) ?>">
      <label class="sr-only" for="lang-pick"><?= te('Language') ?></label>
      <select id="lang-pick" name="locale" onchange="this.form.submit()" aria-label="<?= te('Language') ?>">
        <?php foreach (WORKFORCE_LOCALES as $code => $name): ?>
          <option value="<?= e($code) ?>" <?= locale() === $code ? 'selected' : '' ?>><?= e($name) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn ghost sm"><?= te('Show') ?></button></noscript>
    </form>
    <?php if($u): ?><a href="/account"><?= te('Account') ?></a><form method="post" action="/logout"><?= csrf_field() ?><button class="btn sm"><?= te('Sign out') ?></button></form><?php else: ?><a href="/login"><?= te('Sign in') ?></a><?php endif; ?>
  </div>
</header>

<main class="wrap">
<?php
$working = $u ? current_job() : null;
$unread  = $u ? (int) val('SELECT COUNT(*) FROM notifications
                           WHERE user_id = ? AND read_at IS NULL', [uid()]) : 0;
$atADesk = $u && ! in_array($u['role'] ?? '', ['worker', 'client'], true);
?>
<?php if ($u && ($atADesk || $unread)): ?>
  <div class="workbar">
    <?php if ($atADesk): ?>
      <form method="post" action="/select-project" class="workbar-project">
        <?= csrf_field() ?>
        <label for="working-project"><?= te('Working project') ?></label>
        <select id="working-project" name="job_id" required onchange="this.form.requestSubmit()">
          <?php if (! $working): ?>
            <option value=""><?= te('Select project') ?></option>
          <?php endif; ?>
          <?php foreach (rows('SELECT id,title,status FROM jobs ORDER BY id DESC') as $choice): ?>
            <option value="<?= (int) $choice['id'] ?>"
              <?= (int) ($working['id'] ?? 0) === (int) $choice['id'] ? 'selected' : '' ?>>
              <?= e($choice['title']) ?> &middot; <?= te(ucfirst((string) $choice['status'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn sm"><?= te('Open project') ?></button></noscript>
      </form>
    <?php endif; ?>
    <?php if ($unread): ?>
      <a class="workbar-unread" href="/notifications">
        <?= te(':n waiting for you', ['n' => $unread]) ?>
      </a>
    <?php endif; ?>
  </div>
<?php endif; ?>

  <?php if ($f): ?>
    <div class="flash <?= $f['kind'] === 'err' ? 'err' : 'ok' ?>"><?= e($f['msg']) ?></div>
  <?php endif; ?>
  <?php require $viewPath; ?>
</main>

<?php if($u && ($u['role'] ?? '')!=='client'): ?><script>
let notificationCursor=0;
async function refreshNotifications(){
 try{ const response=await fetch('/notification-feed?after='+notificationCursor,{credentials:'same-origin'});if(!response.ok)return;
 const list=await response.json();for(const n of list){notificationCursor=Math.max(notificationCursor,Number(n.id));
 if('Notification' in window && Notification.permission==='granted'){const notice=new Notification(n.message);notice.onclick=()=>location.href=n.target;}}
 }catch(error){}
}
setInterval(refreshNotifications,30000);
</script><?php endif; ?>
<script>
// Which menu sections this person keeps open. The section holding the
// current page is always open, whatever was remembered.
(function(){
  var groups = document.querySelectorAll('.nav-group');
  if (!groups.length) return;
  var KEY = 'crewvia.nav.open';
  var open = [];
  try { open = JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { open = []; }

  groups.forEach(function (group) {
    var name = group.dataset.section;
    if (!group.open && open.indexOf(name) !== -1) group.open = true;
    group.addEventListener('toggle', function () {
      var at = open.indexOf(name);
      if (group.open && at === -1) open.push(name);
      if (!group.open && at !== -1) open.splice(at, 1);
      try { localStorage.setItem(KEY, JSON.stringify(open)); } catch (e) {}
    });
  });
})();
</script>
</body>
<script>if('serviceWorker' in navigator && window.isSecureContext){navigator.serviceWorker.register('/service-worker.js').catch(()=>{});}</script>
</html>
