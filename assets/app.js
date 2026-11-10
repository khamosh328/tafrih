/* تفریح‌یاب — بدون وابستگی خارجی. منطق خالص (norm / matchItem / rankItems) جدا نوشته شده تا قابل تست باشد. */
(function (root) {
  'use strict';

  var TIMES = ['صبح', 'ظهر', 'عصر', 'شب', 'آخر هفته', 'هروقت'];
  var MODES = { solo: 'تنها', partner: 'با پارتنر', friends: 'دوستانه', family: 'خانوادگی', group: 'گروهی' };
  var MODE_KEYS = ['solo', 'partner', 'friends', 'family', 'group'];
  var EXACT = {}; // کلید ستون‌های دلخواهی که فیلترشان لیست انتخابی (تطبیق دقیق) است
  var DURS = [[1, '۱ ساعت'], [3, '۳ ساعت'], [6, 'نیم‌روز'], [12, 'یک‌روز کامل']];
  // هزینه‌ی اصلی = مجموع قیمت‌های دقیق «هزینه‌های اصلی» (ورودی، بلیت، پارکینگ…)؛ null یعنی هنوز قیمتی ثبت نشده.
  function costOf(it) {
    var t = 0, n = 0;
    (it.costs || []).forEach(function (c) { if (c.price != null && c.price !== '') { t += +c.price; n++; } });
    return n ? t : null;
  }
  var COST_STEPS = [[0, 'فقط رایگان'], [200000, 'تا ۲۰۰ هزار تومان'], [500000, 'تا ۵۰۰ هزار تومان'], [1000000, 'تا ۱ میلیون تومان'], [3000000, 'تا ۳ میلیون تومان']];
  var BUDGET_STEPS = [['0-0', 'رایگان'], ['0-100000', 'تا ۱۰۰ هزار تومان'], ['100000-300000', '۱۰۰ تا ۳۰۰ هزار تومان'], ['300000-700000', '۳۰۰ تا ۷۰۰ هزار تومان'], ['700000-1500000', '۷۰۰ هزار تا ۱.۵ میلیون تومان'], ['1500000-', 'بیشتر از ۱.۵ میلیون تومان']];
  var CHUNK = 36;

  /* ---------- منطق خالص ---------- */

  function norm(s) {
    return String(s == null ? '' : s)
      .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک')
      .replace(/[‌‎‏]/g, '')
      .replace(/[ً-ٟـ]/g, '')
      .replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 1776); })
      .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 1632); })
      .toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function hay(it) {
    if (!it._hay) {
      var ex = it.extra ? Object.keys(it.extra).map(function (k) { return it.extra[k]; }) : [];
      it._hay = norm([it.name, it.category, it.description, it.city, it.area, it.address, it.notes, it.cost_note,
        (it.costs || []).concat(it.costs_extra || []).map(function (c) { return c.label; }).join(' '),
        (it.menu || []).map(function (m) { return m.name; }).join(' '),
        (it.related || []).map(function (r) { return r.name; }).join(' '), ex.join(' ')].join(' '));
    }
    return it._hay;
  }

  /**
   * خروجی: null = رد شد، وگرنه {tier, reasons}. tier 0 = تطابق کامل، 1 = تطابق ضعیف (هروقت / هزینه نامشخص).
   */
  function matchItem(it, f) {
    var tier = 0, reasons = [];
    if (f.q) {
      var toks = norm(f.q).split(' ').filter(Boolean), h = hay(it);
      for (var i = 0; i < toks.length; i++) { if (h.indexOf(toks[i]) === -1) return null; }
    }
    if (f.cat && it.category !== f.cat) return null;
    if (f.dur && !(it.duration_h <= +f.dur)) return null;
    if (f.mode && it.modes.indexOf(f.mode) === -1) return null;
    if (f.city && it.city !== f.city) return null;
    if (f.status === 'todo' && it.done) return null;
    if (f.status === 'done' && !it.done) return null;
    if (f.status === 'fav' && !it.favorite) return null;
    if (f.status === 'guess' && !it.guess_modes) return null;
    if (f.onlyIds && f.onlyIds.indexOf(it.id) === -1) return null;
    if (f.mine && !(f.mineIds && f.mineIds.indexOf(it.id) !== -1)) return null;
    if (f.tags) {
      var wt = String(f.tags).split(',');
      for (var ti = 0; ti < wt.length; ti++) { if (wt[ti] && (it.tags || []).indexOf(wt[ti]) === -1) return null; }
    }
    if (f.open) {
      var oh = parseHours(it.hours);
      if (oh.open === false) return null;
      if (oh.open === null) { tier = 1; reasons.push('ساعت نامشخص'); }
    }
    if (f.budget) {
      // کارت وقتی می‌ماند که حداقل یکی از قیمت‌های ثبت‌شده‌اش (ورودی، هزینه‌های اصلی، هزینه‌های فرعی) در بازه باشد
      var ap = allPrices(it), ec = ap.length ? 1 : null;
      if (ec == null) { tier = 1; reasons.push('هزینه نامشخص'); }
      else if (!ap.some(function (p) { return priceInSpec(p, f.budget); })) return null;
    }
    for (var fk in f) {
      if (fk.slice(0, 2) !== 'x_' || !f[fk]) continue;
      var iv = norm(it.extra && it.extra[fk.slice(2)] || ''), fv = norm(f[fk]);
      if (EXACT[fk] ? iv !== fv : iv.indexOf(fv) === -1) return null;
    }
    if (f.time) {
      if (it.times.indexOf(f.time) !== -1) { /* دقیق */ }
      else if (it.times.indexOf('هروقت') !== -1) { tier = 1; reasons.push('هر ساعتی'); }
      else return null;
    }
    if (f.cost !== '' && f.cost != null) {
      var cc = costOf(it);
      if (cc == null) { tier = 1; reasons.push('هزینه نامشخص'); }
      else if (cc > +f.cost) return null;
    }
    return { tier: tier, reasons: reasons };
  }

  function minCost(it) {
    var m = null;
    (it.costs || []).forEach(function (c) { if (c.price != null && c.price !== '' && (m === null || +c.price < m)) m = +c.price; });
    return m;
  }
  var NO_PRICE = 1e13;
  function sortKey(it, sort) {
    var mc = minCost(it);
    switch (sort) {
      case 'new': return -it.id;
      case 'old': return it.id;
      case 'dur_asc': return it.duration_h * 100000 + it.row;
      case 'dur_desc': return -it.duration_h * 100000 + it.row;
      case 'cost_asc': return (mc == null ? NO_PRICE : mc) + it.row / 1000;
      case 'cost_desc': return (mc == null ? NO_PRICE : -mc) + it.row / 1000;
      case 'fav': return (it.favorite ? 0 : 1) * 1e6 + it.row;
      default: return it.row * 100000 + it.id;
    }
  }
  function textSort(it, sort) {
    return sort === 'cat' || sort === 'cat_name' ? (it.category || '') : sort === 'city' ? (it.city || '') : sort === 'area' ? (it.area || '') : it.name;
  }

  function rankItems(items, f) {
    var out = [];
    for (var i = 0; i < items.length; i++) {
      var m = matchItem(items[i], f);
      if (m) out.push({ it: items[i], tier: m.tier, reasons: m.reasons });
    }
    var sort = f.sort || 'row';
    var TXT = { name: 1, name_desc: 1, cat: 1, city: 1, area: 1 };
    out.sort(function (a, b) {
      // وقتی مرتب‌سازی دلخواه انتخاب شده، ترتیب همان است؛ فقط در حالت پیش‌فرض «هروقت»ها ته لیست می‌روند.
      if (sort === 'row' && a.tier !== b.tier) return a.tier - b.tier;
      if (TXT[sort]) {
        var c = textSort(a.it, sort).localeCompare(textSort(b.it, sort), 'fa');
        if (sort === 'name_desc') c = b.it.name.localeCompare(a.it.name, 'fa');
        if (sort === 'cat' || sort === 'city' || sort === 'area') { if (!textSort(a.it, sort)) c = 1; if (!textSort(b.it, sort)) c = -1; if (!textSort(a.it, sort) && !textSort(b.it, sort)) c = 0; }
        return c || a.it.name.localeCompare(b.it.name, 'fa');
      }
      return sortKey(a.it, sort) - sortKey(b.it, sort);
    });
    return out;
  }

  /* ---------- ویژگی‌ها، ساعت کاری، بودجه، پیشنهاد و برنامه (منطق خالص و قابل تست) ---------- */

  var TAGS = { kids: 'کودک‌پسند', pets: 'همراه حیوان خانگی', parking: 'پارکینگ دارد', wheelchair: 'مناسب ویلچر', indoor: 'سرپوشیده', outdoor: 'فضای باز' };
  var TAG_KEYS = ['kids', 'pets', 'parking', 'wheelchair', 'indoor', 'outdoor'];
  var DAY_IDX = { '': 0, 'یک': 1, 'دو': 2, 'سه': 3, 'چهار': 4, 'پنج': 5 };

  // ساعت و روز هفته به وقت تهران؛ روز ۰ = شنبه … ۶ = جمعه
  function tehranNow(d) {
    d = d || new Date();
    try {
      var p = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Tehran', weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(d), o = {};
      p.forEach(function (x) { o[x.type] = x.value; });
      var dow = { Sat: 0, Sun: 1, Mon: 2, Tue: 3, Wed: 4, Thu: 5, Fri: 6 }[o.weekday];
      return { dow: dow, min: (+o.hour % 24) * 60 + (+o.minute) };
    } catch (e) {
      var t = new Date(d.getTime() + 12600000 + d.getTimezoneOffset() * 60000); // UTC+3:30
      return { dow: (t.getDay() + 1) % 7, min: t.getHours() * 60 + t.getMinutes() };
    }
  }

  function daysIn(seg) {
    var days = [], toks = [], re = /(یک|دو|سه|چهار|پنج)?\s*شنبه|جمعه|آدینه/g, m;
    while ((m = re.exec(seg))) {
      var idx = /جمعه|آدینه/.test(m[0]) ? 6 : DAY_IDX[m[1] || ''];
      toks.push({ idx: idx, end: re.lastIndex, start: m.index });
    }
    for (var i = 0; i < toks.length; i++) {
      var between = i ? seg.slice(toks[i - 1].end, toks[i].start) : '';
      if (i && /^\s*(تا|الی|-|–|—)\s*$/.test(between)) {
        for (var d = toks[i - 1].idx, guard = 0; guard < 8; guard++) { d = (d + 1) % 7; days.push(d); if (d === toks[i].idx) break; }
      } else days.push(toks[i].idx);
    }
    if (/همه ?روزه|هر ?روز|روزانه|همه ?ایام|هفت ?روز هفته/.test(seg)) return [0, 1, 2, 3, 4, 5, 6];
    return days;
  }

  /**
   * ساعت کاری متنی → {open: true|false|null}. null یعنی با اطمینان نمی‌شود فهمید (نمایش داده نمی‌شود).
   * فقط الگوهای معمول فارسی را می‌فهمد: «همه روزه ۹ تا ۲۱»، «شنبه تا چهارشنبه ۹ تا ۱۷، پنجشنبه ۹ تا ۱۳»، «۲۴ ساعته»، «جمعه تعطیل».
   */
  function parseHours(text, date) {
    var t = norm(text);
    if (!t) return { open: null };
    var now = tehranNow(date);
    if (/24 ?ساعت|شبانه ?روز/.test(t) && !/تعطیل/.test(t)) return { open: true };
    var segs = t.split(/[،,؛;\n|]/), rules = [], closed = [];
    segs.forEach(function (sg) {
      var days = daysIn(sg), ranges = [], re = /(\d{1,2})(?::(\d{2}))?\s*(صبح|ظهر|عصر|شب)?\s*(?:تا|الی|-|–|—)\s*(\d{1,2})(?::(\d{2}))?\s*(صبح|ظهر|عصر|شب)?/g, m;
      while ((m = re.exec(sg))) {
        var a = +m[1], b = +m[4];
        if (a > 24 || b > 24 || (m[2] && +m[2] > 59) || (m[5] && +m[5] > 59)) continue;
        if ((m[3] === 'عصر' || m[3] === 'شب') && a < 12) a += 12;
        if ((m[6] === 'عصر' || m[6] === 'شب') && b < 12 && b !== 0) b += 12;
        ranges.push([a * 60 + (m[2] ? +m[2] : 0), b * 60 + (m[5] ? +m[5] : 0)]);
      }
      if (/تعطیل/.test(sg) && days.length && !ranges.length) closed = closed.concat(days);
      else if (ranges.length) rules.push({ days: days.length ? days : null, ranges: ranges });
    });
    if (!rules.length) return { open: closed.indexOf(now.dow) !== -1 ? false : null };
    if (closed.indexOf(now.dow) !== -1) return { open: false };
    var specific = rules.filter(function (r) { return r.days && r.days.indexOf(now.dow) !== -1; });
    var use = specific.length ? specific : rules.filter(function (r) { return !r.days; });
    if (!use.length) return { open: rules.every(function (r) { return r.days; }) ? false : null };
    var open = false;
    use.forEach(function (r) {
      r.ranges.forEach(function (rg) {
        var s = rg[0], e = rg[1];
        if (e <= s) { if (now.min >= s || now.min < e) open = true; }
        else if (now.min >= s && now.min < e) open = true;
      });
    });
    return { open: open };
  }

  // همه‌ی قیمت‌های عددی ثبت‌شده در کارت: هزینه‌های اصلی (ورودی و ...) و هزینه‌های فرعی/پیشنهادی
  function allPrices(it) {
    var out = [];
    (it.costs || []).concat(it.costs_extra || []).forEach(function (c) {
      if (c.price == null || c.price === '') return;
      var p = +c.price; if (!isNaN(p)) out.push(p);
    });
    return out;
  }
  // spec: 'min-max' یعنی بیشتر از min و حداکثر max؛ '0-0' فقط رایگان؛ 'min-' بدون سقف
  function priceInSpec(p, spec) {
    var bp = String(spec).split('-');
    if (bp.length === 1) return p <= +bp[0];
    var mn = +bp[0] || 0, mx = bp[1] === '' ? Infinity : +bp[1];
    if (mx === 0) return p === 0;
    return p > mn && p <= mx;
  }

  // هزینه‌ی ورود برای هر نفر: ردیف‌هایی که ورودی/بلیت‌اند؛ اگر نبود، کمترین هزینه‌ی اصلی. null = نامشخص
  function entryCost(it) {
    var best = null, any = null;
    (it.costs || []).forEach(function (c) {
      if (c.price == null || c.price === '') return;
      var p = +c.price;
      if (any === null || p < any) any = p;
      if (/ورود|بلیت|بلیط|ticket|هر ?نفر|نفره|بزرگسال/i.test(c.label || '') && (best === null || p < best)) best = p;
    });
    return best !== null ? best : any;
  }

  /**
   * موارد ناقص یک کارت (برای مدیر). هر مورد: {k: کلید، t: عنوان، w: 2 مهم / 1 کم‌اهمیت}
   */
  function missingOf(it) {
    var m = [];
    function add(k, t, w) { m.push({ k: k, t: t, w: w }); }
    var has = function (v) { return v != null && String(v).trim() !== ''; };
    if (!(it.images && it.images.length)) add('images', 'عکس ندارد', 2);
    if (!has(it.description) || String(it.description).trim().length < 30) add('description', has(it.description) ? 'توضیحات خیلی کوتاه است' : 'توضیحات ندارد', 2);
    if (!(it.lat != null && it.lat !== '' && !isNaN(+it.lat) && it.lng != null && it.lng !== '' && !isNaN(+it.lng))) add('map', 'لوکیشن دقیق روی نقشه ثبت نشده', 2);
    if (!has(it.location)) add('location', 'لینک مسیریابی (نشان) ندارد', 2);
    var paid = (it.costs || []).filter(function (c) { return c.price != null && c.price !== ''; });
    if (!paid.length) add('costs', 'هیچ قیمتی ثبت نشده', 2);
    else if (!it.prices_at) add('prices_at', 'تاریخ به‌روزرسانی قیمت ثبت نشده', 1);
    if (!has(it.hours)) add('hours', 'ساعت کاری ندارد', 2);
    if (!has(it.address)) add('address', 'آدرس ندارد', 1);
    if (!has(it.area)) add('area', 'محدوده ندارد', 1);
    if (!has(it.city)) add('city', 'شهر ندارد', 2);
    if (!has(it.website)) add('website', 'وب‌سایت / صفحه رسمی ندارد', 1);
    if (!has(it.phone)) add('phone', 'تلفن ندارد', 1);
    if (!(it.times && it.times.length)) add('times', 'زمان مناسب مشخص نیست', 2);
    if (!(it.modes && it.modes.length) || it.guess_modes) add('modes', it.guess_modes ? 'حالت‌های همراه هنوز بازبینی نشده (حدسی)' : 'حالت همراه مشخص نیست', 1);
    if (!(it.tags && it.tags.length)) add('tags', 'ویژگی‌ها (کودک‌پسند، پارکینگ و ...) ثبت نشده', 1);
    if (!(it.related && it.related.length)) add('related', 'تفریحات تکمیلی ندارد', 1);
    return m;
  }

  function modeFor(people, family) {
    if (family) return 'family';
    return people <= 1 ? 'solo' : people === 2 ? 'partner' : people <= 5 ? 'friends' : 'group';
  }

  /**
   * ویزارد «امشب چه کنیم؟». o: {people, family, budget, hours, open, time, city}; ctx: {rainy, date, jitter}
   * فیلتر سخت: همراه، مدت، بودجه (اگر هزینه معلوم باشد)، بسته بودن قطعی. بقیه امتیاز است و دلیل هر پیشنهاد نوشته می‌شود.
   */
  function suggest(items, o, ctx) {
    ctx = ctx || {};
    var mode = modeFor(+o.people || 1, !!o.family), out = [];
    items.forEach(function (it) {
      if (it.modes.indexOf(mode) === -1) return;
      if (o.hours && !(it.duration_h <= +o.hours)) return;
      if (o.city && it.city !== o.city) return;
      if (it.done && ctx.hideDone) return;
      var score = 50, why = [], ec = entryCost(it);
      if (o.budget) {
        if (ec != null && ec > +o.budget) return;
        if (ec != null) { score += 5; why.push(ec === 0 ? 'رایگان' : 'ورودی از ' + fa(ec) + ' تومان'); } else score -= 6;
      } else if (ec != null) why.push(ec === 0 ? 'رایگان' : 'ورودی از ' + fa(ec) + ' تومان');
      var hrs = parseHours(it.hours, ctx.date);
      if (hrs.open === false) { if (o.open) return; score -= 12; why.push('الان بسته است'); }
      else if (hrs.open === true) { score += 8; why.push('الان باز است'); }
      if (ctx.rainy) {
        if (it.tags && it.tags.indexOf('indoor') !== -1) { score += 14; why.push('سرپوشیده برای هوای بارانی'); }
        if (it.tags && it.tags.indexOf('outdoor') !== -1) score -= 16;
      } else if (it.tags && it.tags.indexOf('outdoor') !== -1) score += 3;
      if (o.family && it.tags && it.tags.indexOf('kids') !== -1) { score += 8; why.push('کودک‌پسند'); }
      if (o.time && it.times.indexOf(o.time) !== -1) { score += 6; why.push('مناسب ' + o.time); }
      if (o.hours && it.duration_h <= +o.hours && +o.hours - it.duration_h <= 2) score += 4;
      if (it.favorite) score += 6;
      if (it.images && it.images.length) score += 4;
      why.push('حدود ' + durLabel(it.duration_h));
      score += (ctx.jitter ? ctx.jitter() : 0);
      out.push({ it: it, score: score, why: why });
    });
    out.sort(function (a, b) { return b.score - a.score; });
    return out;
  }

  function hav(a, b, c, d) {
    var R = 6371, p = Math.PI / 180, x = (c - a) * p, y = (d - b) * p;
    var q = Math.sin(x / 2) * Math.sin(x / 2) + Math.cos(a * p) * Math.cos(c * p) * Math.sin(y / 2) * Math.sin(y / 2);
    return 2 * R * Math.asin(Math.sqrt(q));
  }
  function timeClass(it) {
    var o = { 'صبح': 0, 'ظهر': 1, 'عصر': 2, 'شب': 3 }, m = 9;
    (it.times || []).forEach(function (t) { if (o[t] != null && o[t] < m) m = o[t]; });
    return m === 9 ? 1.5 : m;
  }

  /**
   * برنامه‌ی روز: ترتیب بر اساس زمان مناسب و نزدیک‌ترین بعدی، سپس زمان‌بندی از ساعت شروع.
   * رفت‌وآمد تخمینی است (۳ دقیقه برای هر کیلومتر + ۸ دقیقه؛ بدون مختصات ۲۰ دقیقه).
   */
  function buildPlan(items, startMin, people, date, fixed) {
    var left = items.slice(0, 6), order = [];
    if (fixed) { order = left; left = []; }
    else left.sort(function (a, b) { return timeClass(a) - timeClass(b); });
    while (left.length) {
      var cur = order.length ? order[order.length - 1] : null, pick = 0;
      var cls = Math.min.apply(null, left.map(timeClass));
      var cand = left.filter(function (x) { return timeClass(x) <= cls + 0.5; });
      if (cur && cur.lat != null) {
        var bd = 1e9;
        cand.forEach(function (x) { if (x.lat != null) { var d = hav(cur.lat, cur.lng, x.lat, x.lng); if (d < bd) { bd = d; pick = left.indexOf(x); } } });
        if (bd === 1e9) pick = left.indexOf(cand[0]);
      } else pick = left.indexOf(cand[0]);
      order.push(left.splice(pick, 1)[0]);
    }
    var t = startMin, stops = [], total = 0, unknown = 0, base = tehranNow(date);
    order.forEach(function (it, i) {
      if (i) {
        var p = order[i - 1], tv = (p.lat != null && it.lat != null) ? Math.round(hav(p.lat, p.lng, it.lat, it.lng) * 3 + 8) : 20;
        t += tv; stops[stops.length - 1].travel = tv;
      }
      var dur = Math.min(it.duration_h, 6) * 60, ec = entryCost(it);
      if (ec == null) unknown++; else total += ec * (people || 1);
      var warn = false;
      var hrs = parseHours(it.hours, new Date(Date.now() + (t - base.min) * 60000));
      if (hrs.open === false) warn = true;
      stops.push({ it: it, start: t, end: t + dur, warn: warn, travel: 0 });
      t += dur;
    });
    return { stops: stops, total: total, unknown: unknown, end: t };
  }

  function perms(a) {
    if (a.length <= 1) return [a];
    var out = [];
    a.forEach(function (x, i) { perms(a.slice(0, i).concat(a.slice(i + 1))).forEach(function (r) { out.push([x].concat(r)); }); });
    return out;
  }
  function pathKm(arr) { var k = 0; for (var i = 1; i < arr.length; i++) k += hav(arr[i - 1].lat, arr[i - 1].lng, arr[i].lat, arr[i].lng); return k; }
  /**
   * مسیر یک‌طرفه: از بین پیشنهادهای دارای مختصات چند مکان نزدیک به هم انتخاب می‌شود که در زمان داده‌شده جا بگیرند
   * و ترتیبشان طوری است که کل مسیر کوتاه‌ترین شود (از یک سر شروع، بین راه به بقیه، و پایان در آخری).
   * cands: خروجی suggest. o: {hours}. ctx: {avoid: [id شروع‌های قبلی]}. فاصله‌ها خط مستقیم‌اند.
   */
  function planRoute(cands, o, ctx) {
    ctx = ctx || {};
    var pool = cands.filter(function (r) { return r.it.lat != null && r.it.lng != null && r.it.lat !== '' && !isNaN(+r.it.lat) && !isNaN(+r.it.lng); }).slice(0, 40);
    if (pool.length < 2) return null;
    var limit = (+o.hours || 3) * 60 * 1.2, maxStops = 5, best = null;
    function dkm(a, b) { return hav(+a.lat, +a.lng, +b.lat, +b.lng); }
    pool.slice(0, 12).forEach(function (seed) {
      if ((ctx.avoid || []).indexOf(seed.it.id) !== -1) return;
      var ch = [seed], used = Math.min(seed.it.duration_h, 6) * 60;
      while (ch.length < maxStops) {
        var pick = null, pv = -1e9;
        pool.forEach(function (c) {
          if (ch.indexOf(c) !== -1) return;
          var near = Math.min.apply(null, ch.map(function (x) { return dkm(x.it, c.it); }));
          var cen = { lat: ch.reduce(function (a, x) { return a + +x.it.lat; }, 0) / ch.length, lng: ch.reduce(function (a, x) { return a + +x.it.lng; }, 0) / ch.length };
          if (near > 6 || dkm(cen, c.it) > 10) return;
          var need = used + Math.min(c.it.duration_h, 6) * 60 + near * 3 + 8;
          if (need > limit) return;
          var v = c.score - 3 * near;
          if (v > pv) { pv = v; pick = c; }
        });
        if (!pick) break;
        used += Math.min(pick.it.duration_h, 6) * 60 + Math.min.apply(null, ch.map(function (x) { return dkm(x.it, pick.it); })) * 3 + 8;
        ch.push(pick);
      }
      var bo = null, bk = 1e9;
      perms(ch).forEach(function (pm) {
        var k = pathKm(pm.map(function (x) { return x.it; }));
        var tc = 0; pm.forEach(function (x, i) { tc += i * timeClass(x.it) * 0.01; });
        if (k + tc < bk) { bk = k + tc; bo = pm; }
      });
      var km = pathKm(bo.map(function (x) { return x.it; }));
      var val = bo.reduce(function (a, x) { return a + x.score; }, 0) - 2 * km + (ctx.jitter ? ctx.jitter() : 0);
      if (!best || val > best.val) best = { val: val, order: bo, seed: seed.it.id };
    });
    if (!best) return null;
    var stops = [], mins = 0, total = 0;
    best.order.forEach(function (r, i) {
      var km = i ? dkm(best.order[i - 1].it, r.it) : 0, tv = i ? Math.round(km * 3 + 8) : 0;
      var dur = Math.min(r.it.duration_h, 6) * 60;
      stops.push({ it: r.it, why: r.why, km: km, travel: tv, dur: dur });
      mins += tv + dur; total += km;
    });
    return { stops: stops, km: total, minutes: mins, seed: best.seed };
  }
  function kmLabel(k) { return k < 1 ? 'کمتر از ۱ کیلومتر' : 'حدود ' + fa(Math.round(k * 10) / 10) + ' کیلومتر'; }
  // متن سناریو برای هر مرحله
  function routeStory(route) {
    var n = route.stops.length;
    return route.stops.map(function (s, i) {
      var place = '«' + s.it.name + '»' + (s.it.area ? ' (' + s.it.area + ')' : '');
      if (i === 0) return (n === 1 ? 'پیشنهاد: ' : 'شروع از ') + place + ' و حدود ' + durLabel(s.it.duration_h) + ' وقت بگذارید.';
      var pre = 'از «' + route.stops[i - 1].it.name + '» ' + kmLabel(s.km) + ' (حدود ' + fa(s.travel) + ' دقیقه با رفت‌وآمد) تا ';
      return pre + place + (i === n - 1 ? '؛ پایان مسیر.' : '؛ حدود ' + durLabel(s.it.duration_h) + '.');
    });
  }

  function fa(n) { return Number(n).toLocaleString('fa-IR'); }
  function durLabel(h) {
    for (var i = 0; i < DURS.length; i++) if (DURS[i][0] === h) return DURS[i][1];
    return fa(h) + ' ساعت';
  }
  function safeUrl(u) { return /^https?:\/\//i.test(u || '') ? u : ''; }
  function hostOf(u) { try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return u; } }

  var logic = { norm: norm, matchItem: matchItem, rankItems: rankItems, durLabel: durLabel, parseHours: parseHours, entryCost: entryCost, missingOf: missingOf, suggest: suggest, buildPlan: buildPlan, planRoute: planRoute, routeStory: routeStory, allPrices: allPrices, priceInSpec: priceInSpec, modeFor: modeFor, tehranNow: tehranNow };
  if (typeof module !== 'undefined' && module.exports) { module.exports = logic; return; }
  if (typeof document === 'undefined') return;

  /* ---------- UI ---------- */

  var CFG = root.TFY_CFG || {};
  var rootEl = document.getElementById('tfy-root') || document.getElementById('tfy-admin-form') || document.getElementById('tfy-admin-manage');
  if (!rootEl) return;

  var S = { items: [], list: [], shown: 0, ai: false, extra: [], f: loadFilters(), cards: {}, lastRandom: 0 };
  var ADMIN = !!CFG.adminForm;
  var CAN = !!CFG.canEdit;      // فقط در تنظیمات افزونه (مدیر)
  var MANAGE = !!CFG.manage && !!document.getElementById('tfy-admin-manage');
  var SUB_TOKEN = {};           // توکن آپلود عکس برای پیشنهاد عمومی

  function h(tag, attrs) {
    var el = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        var v = attrs[k];
        if (v == null || v === false) continue;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k === 'value' || k === 'checked' || k === 'disabled' || k === 'selected') el[k] = v;
        else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
        else el.setAttribute(k, v === true ? '' : v);
      }
    }
    for (var i = 2; i < arguments.length; i++) add(el, arguments[i]);
    return el;
  }
  function add(el, c) {
    if (c == null || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { add(el, x); }); return; }
    el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }

  var toastEl, toastT;
  function toast(msg) {
    if (!toastEl) { toastEl = h('div', { class: 'tfy-toast', role: 'status' }); document.body.appendChild(toastEl); }
    toastEl.textContent = msg; toastEl.style.display = 'block';
    clearTimeout(toastT); toastT = setTimeout(function () { toastEl.style.display = 'none'; }, 3200);
  }

  function api(method, path, body, form) {
    var opts = { method: method, headers: {}, credentials: CAN ? 'same-origin' : 'omit' };
    if (CAN && CFG.nonce) opts.headers['X-WP-Nonce'] = CFG.nonce;
    if (form) opts.body = form;
    else if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch(CFG.rest + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) {
          var e = new Error((j && j.message) || ('خطا ' + r.status)); e.status = r.status; throw e;
        }
        return j;
      });
    });
  }

  function loadFilters() {
    var d = { q: '', time: '', dur: '', cat: '', mode: '', cost: '', city: '', status: '', sort: 'row', tags: '', open: '', budget: '', mine: '' };
    try { var s = JSON.parse(sessionStorage.getItem('tfy_f') || 'null'); if (s) for (var k in s) if (typeof s[k] === 'string' && (k in d || k.slice(0, 2) === 'x_')) d[k] = s[k]; } catch (e) {}
    return d;
  }
  function saveFilters() { try { sessionStorage.setItem('tfy_f', JSON.stringify(S.f)); } catch (e) {} }

  /* ---------- مودال ---------- */
  var stack = [];
  function openModal(build, opts) {
    opts = opts || {};
    var prevFocus = document.activeElement;
    var ov = h('div', { class: 'tfy tfy-ov', dir: 'rtl' });
    var box = h('div', { class: 'modal' + (opts.small ? ' sm' : ''), role: 'dialog', 'aria-modal': 'true' });
    ov.appendChild(box);
    var m = { el: ov, box: box };
    if (opts.inline) ov.className = 'tfy tfy-ov inl';
    m.close = function () {
      var i = stack.indexOf(m); if (i === -1) return;
      if (opts.beforeClose && opts.beforeClose() === false) return;
      stack.splice(i, 1); ov.remove();
      if (opts.inline) { opts.onClose && opts.onClose(); return; }
      if (!stack.length) document.documentElement.classList.remove('tfy-lock');
      if (prevFocus && prevFocus.focus) try { prevFocus.focus(); } catch (e) {}
    };
    m.rebuild = function () { clear(box); add(box, build(m)); };
    ov.addEventListener('mousedown', function (e) { if (e.target === ov && !opts.sticky) m.close(); });
    m.rebuild();
    (opts.inline || document.body).appendChild(ov);
    if (!opts.inline) document.documentElement.classList.add('tfy-lock');
    stack.push(m);
    var f = box.querySelector('[data-autofocus]') || box.querySelector('.x');
    if (f && !opts.inline) f.focus();
    return m;
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && stack.length) { var m = stack[stack.length - 1]; if (!m.sticky) m.close(); }
  });
  function head(title, m) {
    return h('div', { class: 'mh' }, h('h2', { text: title }), h('button', { class: 'x', type: 'button', 'aria-label': 'بستن', text: '×', onclick: m.close }));
  }
  function confirmBox(title, text, okLabel, danger) {
    return new Promise(function (res) {
      var done = false;
      var m = openModal(function (m) {
        return [head(title, m), h('div', { class: 'mb', text: text }),
          h('div', { class: 'mf' }, h('span', { class: 'sp' }),
            h('button', { class: 'btn', type: 'button', text: 'انصراف', onclick: function () { done = true; m.close(); res(false); } }),
            h('button', { class: 'btn ' + (danger ? 'btn-danger' : 'btn-primary'), type: 'button', 'data-autofocus': '', text: okLabel, onclick: function () { done = true; m.close(); res(true); } }))];
      }, { small: true, beforeClose: function () { if (!done) { done = true; res(false); } } });
    });
  }

  /* ---------- صفحه اصلی ---------- */
  var ui = {};

  function start() {
    clear(rootEl);
    rootEl.appendChild(h('div', { class: 'loadmore', text: 'در حال بارگذاری…' }));
    api('GET', MANAGE ? 'items?all=1' : 'items').then(function (r) {
      S.items = r.items || []; S.ai = !!r.ai; S.extra = r.extra_fields || [];
      if (MANAGE) { buildManage(); return; }
      if (ADMIN) { refreshOptions0(); openForm(null, { inline: rootEl }); return; }
      buildApp(); refreshOptions(); applyFilters(); handleDeepLink();
    }).catch(function (ex) {
      clear(rootEl); rootEl.appendChild(h('div', { class: 'empty', text: 'خطا در بارگذاری: ' + ex.message }));
    });
  }

  function handleDeepLink() {
    var q; try { q = new URLSearchParams(location.search); } catch (e) { return; }
    function ids(v) { return String(v || '').split(',').map(Number).filter(function (n) { return n > 0 && getItem(n); }).slice(0, 60); }
    var li = ids(q.get('list')), pi = ids(q.get('plan')), c = +q.get('card');
    if (li.length) {
      S.only = li; applyFilters(); clear(ui.banner); ui.banner.hidden = false;
      add(ui.banner, [h('span', { text: 'لیستی که برایتان فرستاده‌اند (' + fa(li.length) + ' مورد) نمایش داده می‌شود.' }),
        h('button', { class: 'btn btn-sm', type: 'button', text: 'افزودن همه به لیست من', onclick: function () { li.forEach(function (id) { if (!inMy(id)) MY.ids.push(id); }); saveMy(); toast('اضافه شد.'); } }),
        h('button', { class: 'btn btn-sm', type: 'button', text: 'نمایش همه تفریحات', onclick: function () { S.only = null; ui.banner.hidden = true; applyFilters(); } })]);
    }
    if (pi.length) openPlan(pi, { t: q.get('t'), p: +q.get('p') || 0, fixed: q.get('o') === '1' });
    else if (c && getItem(c)) openDetail(c);
  }

  function sel(name, label, opts, onChange) {
    var s = h('select', { class: 'inp', 'aria-label': label, onchange: function () { S.f[name] = s.value; saveFilters(); onChange && onChange(); applyFilters(); } });
    s.appendChild(h('option', { value: '', text: label }));
    opts.forEach(function (o) { s.appendChild(h('option', { value: String(o[0]), text: o[1] })); });
    s.value = S.f[name] || '';
    return s;
  }

  function buildApp() {
    clear(rootEl);
    ui = {};
    ui.myBtn = h('button', { class: 'btn', type: 'button', text: 'لیست من', onclick: openMyList });
    var acts = h('div', { class: 'acts' },
      h('button', { class: 'btn btn-primary', type: 'button', text: 'امشب چه کنیم؟', onclick: openWizard }),
      h('button', { class: 'btn', type: 'button', text: 'یک مورد تصادفی', onclick: pickRandom }),
      ui.myBtn,
      h('button', { class: 'btn', type: 'button', text: CAN ? '+ افزودن تفریح' : 'پیشنهاد تفریح جدید', onclick: function () { openForm(null); } }),
      null);
    var top = h('div', { class: 'top' }, h('h1', { text: 'تفریح‌یاب' }), acts);
    ui.weather = h('div', { class: 'wbar', hidden: true });
    ui.banner = h('div', { class: 'sbanner', hidden: true });

    ui.q = h('input', { class: 'inp', type: 'search', placeholder: 'جستجو در نام، توضیح، محله، آدرس…', value: S.f.q, 'aria-label': 'جستجو' });
    var qt; ui.q.addEventListener('input', function () { clearTimeout(qt); qt = setTimeout(function () { S.f.q = ui.q.value; saveFilters(); applyFilters(); }, 140); });
    var fwrap = h('div', { class: 'fwrap' });
    var toggle = h('button', { class: 'btn filtertoggle', type: 'button', text: 'فیلترها', onclick: function () { fwrap.classList.toggle('open'); } });

    ui.cat = sel('cat', 'همه دسته‌ها', []);
    ui.dur = sel('dur', 'مدت: بدون محدودیت', [[1, 'حداکثر ۱ ساعت'], [3, 'حداکثر ۳ ساعت'], [6, 'حداکثر نیم‌روز']]);
    ui.mode = sel('mode', 'همراه: همه', MODE_KEYS.map(function (k) { return [k, MODES[k]]; }));
    ui.cost = sel('cost', 'هزینه: بدون محدودیت', COST_STEPS);
    ui.city = sel('city', 'همه شهرها', []);
    ui.status = !CAN ? null : sel('status', 'وضعیت: همه', [['todo', 'انجام نشده'], ['done', 'انجام شده'], ['fav', 'علاقه‌مندی'], ['guess', 'حالت‌های بازبینی‌نشده']]);
    ui.sort = h('select', { class: 'inp', 'aria-label': 'مرتب‌سازی', onchange: function () { S.f.sort = ui.sort.value; saveFilters(); applyFilters(); } });
    [['row', 'مرتب‌سازی: پیش‌فرض'], ['cost_asc', 'ارزان‌ترین (کمترین قیمت شروع)'], ['cost_desc', 'گران‌ترین (بیشترین قیمت شروع)'], ['name', 'حروف الفبا (الف تا ی)'], ['name_desc', 'حروف الفبا (ی تا الف)'], ['cat', 'دسته‌بندی'], ['city', 'شهر'], ['area', 'محدوده'], ['dur_asc', 'کوتاه‌ترین مدت'], ['dur_desc', 'طولانی‌ترین مدت'], ['new', 'جدیدترین'], ['old', 'قدیمی‌ترین']].concat(CAN ? [['fav', 'علاقه‌مندی‌ها اول']] : []).forEach(function (o) { ui.sort.appendChild(h('option', { value: o[0], text: o[1] })); });
    ui.sort.value = S.f.sort || 'row';

    ui.timeChips = h('div', { class: 'chips' }, h('span', { class: 'cl', text: 'زمان مناسب:' }));
    TIMES.forEach(function (t) {
      var b = h('button', { class: 'chip', type: 'button', text: t, 'aria-pressed': 'false', onclick: function () {
        S.f.time = S.f.time === t ? '' : t; saveFilters(); syncChips(); applyFilters();
      } });
      b.dataset.t = t; ui.timeChips.appendChild(b);
    });

    ui.budget = sel('budget', 'بودجه برای هر نفر: همه', BUDGET_STEPS);
    ui.tagChips = h('div', { class: 'chips' }, h('span', { class: 'cl', text: 'ویژگی:' }));
    TAG_KEYS.forEach(function (k) {
      var b = h('button', { class: 'chip', type: 'button', text: TAGS[k], 'aria-pressed': 'false', onclick: function () {
        var a = S.f.tags ? S.f.tags.split(',') : [], i = a.indexOf(k); if (i === -1) a.push(k); else a.splice(i, 1);
        S.f.tags = a.join(','); saveFilters(); syncChips(); applyFilters();
      } });
      b.dataset.k = k; ui.tagChips.appendChild(b);
    });
    function tog(label, key) {
      var b = h('button', { class: 'chip', type: 'button', text: label, 'aria-pressed': 'false', onclick: function () { S.f[key] = S.f[key] ? '' : '1'; saveFilters(); syncChips(); applyFilters(); } });
      b.dataset.tg = key; return b;
    }
    ui.togs = h('div', { class: 'chips' }, tog('الان باز است', 'open'), tog('فقط لیست من', 'mine'));
    ui.count = h('span', { text: '' });
    ui.clear = h('button', { class: 'linkbtn', type: 'button', text: 'پاک کردن فیلترها', onclick: function () {
      S.f = { q: '', time: '', dur: '', cat: '', mode: '', cost: '', city: '', status: '', sort: S.f.sort, tags: '', open: '', budget: '', mine: '' };
      S.only = null; ui.banner.hidden = true; ui.budget.value = '';
      ui.q.value = ''; [ui.cat, ui.dur, ui.mode, ui.cost, ui.city, ui.status].forEach(function (s) { if (s) s.value = ''; });
      buildExtraFilters();
      saveFilters(); syncChips(); applyFilters();
    } });

    ui.filters = h('div', { class: 'filters' }, ui.cat, ui.dur, ui.mode, ui.cost, ui.city, ui.status, ui.budget, ui.sort);
    add(fwrap, [ui.filters, ui.timeChips, ui.tagChips, ui.togs]);
    var panel = h('div', { class: 'panel' }, h('div', { class: 'searchrow' }, ui.q, toggle), fwrap, h('div', { class: 'meta' }, ui.count, ui.clear));
    ui.grid = h('div', { class: 'grid' });
    ui.empty = h('div', { class: 'empty', hidden: true, text: 'موردی با این فیلترها پیدا نشد.' });
    ui.sentinel = h('div', { class: 'sentinel' });
    ui.more = h('button', { class: 'btn', type: 'button', hidden: true, text: 'نمایش بیشتر', onclick: function () { renderMore(); } });
    ui.listWrap = h('div', { class: 'listwrap' }, panel, ui.grid, ui.empty, h('div', { style: 'text-align:center;margin:12px 0' }, ui.more), ui.sentinel);
    ui.mapWrap = h('div', { class: 'mapwrap', hidden: true });
    ui.tabs = h('div', { class: 'tabs', role: 'tablist' },
      h('button', { class: 'tab on', type: 'button', text: 'تفریحات', onclick: function () { showTab('list'); } }),
      h('button', { class: 'tab', type: 'button', text: 'نقشه', onclick: function () { showTab('map'); } }));
    add(rootEl, [top, ui.weather, ui.banner, ui.tabs, ui.listWrap, ui.mapWrap]);
    syncChips(); updMyBtn(); loadWeather();

    if ('IntersectionObserver' in root) {
      ui.io = new IntersectionObserver(function (es) {
        if (es[0].isIntersecting && S.shown < S.list.length) { renderMore(); ui.io.unobserve(ui.sentinel); ui.io.observe(ui.sentinel); }
      }, { rootMargin: '1400px' });
      ui.io.observe(ui.sentinel);
    }
  }

  function showTab(t) {
    var order = ['list', 'map'];
    ui.listWrap.hidden = t !== 'list'; ui.mapWrap.hidden = t !== 'map';
    Array.prototype.forEach.call(ui.tabs.children, function (b, i) { b.classList.toggle('on', order[i] === t); });
    if (t === 'map') openMap();
  }

  /* ---------- نقشه ---------- */
  var MAP = null, mapLibP = null;
  function loadScript(src) {
    return new Promise(function (res, rej) { var s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = function () { rej(new Error('بارگذاری ' + src + ' ناموفق بود')); }; document.head.appendChild(s); });
  }
  function loadMapLib() {
    if (root.L && root.L.markerClusterGroup) return Promise.resolve();
    if (mapLibP) return mapLibP;
    var base = CFG.assets + 'vendor/';
    ['leaflet.css', 'MarkerCluster.css', 'MarkerCluster.Default.css'].forEach(function (f) {
      var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = base + f; document.head.appendChild(l);
    });
    mapLibP = loadScript(base + 'leaflet.js').then(function () { return loadScript(base + 'leaflet.markercluster.js'); });
    mapLibP.catch(function () { mapLibP = null; });
    return mapLibP;
  }
  function geoItems() { return S.items.filter(function (x) { return x.lat != null && x.lng != null; }); }
  function openMap() {
    if (MAP && MAP.map) { setTimeout(function () { MAP.map.invalidateSize(); }, 50); return; }
    clear(ui.mapWrap);
    ui.mapWrap.appendChild(h('div', { class: 'loadmore', text: 'در حال بارگذاری نقشه…' }));
    loadMapLib().then(buildMap).catch(function (e) {
      clear(ui.mapWrap); ui.mapWrap.appendChild(h('div', { class: 'empty', text: 'نقشه بارگذاری نشد: ' + e.message }));
    });
  }
  function buildMap() {
    clear(ui.mapWrap);
    var L = root.L, all = geoItems(), missing = S.items.length - all.length;
    MAP = { cat: '', me: null };
    var catSel = h('select', { class: 'inp', 'aria-label': 'دسته', onchange: function () { MAP.cat = catSel.value; drawMarkers(); } }, h('option', { value: '', text: 'همه دسته‌ها' }));
    var cats = {}; all.forEach(function (x) { if (x.category) cats[x.category] = 1; });
    Object.keys(cats).sort(function (a, b) { return a.localeCompare(b, 'fa'); }).forEach(function (c) { catSel.appendChild(h('option', { value: c, text: c })); });
    var status = h('div', { class: 'mapstat', text: fa(all.length) + ' تفریح روی نقشه' + (missing ? ' — ' + fa(missing) + ' مورد مختصات ندارد و روی نقشه نیست' : '') });
    var meNote = h('div', { class: 'menote', hidden: true });
    var meBtn = h('button', { class: 'btn btn-primary', type: 'button', onclick: locate }, h('span', { class: 'ico', 'aria-hidden': 'true' }), 'موقعیت من');
    ui.mapBox = h('div', { class: 'mapbox', dir: 'ltr' });
    add(ui.mapWrap, [h('div', { class: 'maptools' }, meBtn, catSel), status, meNote, ui.mapBox]);

    var map = L.map(ui.mapBox, { zoomControl: true }).setView([35.70, 51.40], 11);
    L.tileLayer(CFG.tiles || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    var cluster = L.markerClusterGroup({ chunkedLoading: true, maxClusterRadius: 50 });
    map.addLayer(cluster);
    MAP.map = map; MAP.cluster = cluster; MAP.meLayer = null;

    function popup(it) {
      return h('div', { class: 'mappop', dir: 'rtl' }, h('strong', { text: it.name }),
        it.category ? h('div', { class: 'mapsub', text: it.category + (it.area ? ' — ' + it.area : '') }) : null,
        h('button', { class: 'btn btn-primary btn-sm', type: 'button', text: 'باز کردن کارت', onclick: function () { openDetail(it.id); } }));
    }
    function drawMarkers() {
      cluster.clearLayers();
      var pts = [];
      all.forEach(function (it) {
        if (MAP.cat && it.category !== MAP.cat) return;
        var m = L.marker([it.lat, it.lng], { title: it.name });
        m.bindPopup(popup(it));
        cluster.addLayer(m); pts.push([it.lat, it.lng]);
      });
      MAP.pts = pts;
    }

    /* موقعیت من: چند نمونه‌ی GPS می‌گیریم و دقیق‌ترین را برمی‌داریم؛ دایره‌ی ۱ کیلومتر دور آن */
    function showMe(lat, lng, acc) {
      if (MAP.meLayer) map.removeLayer(MAP.meLayer);
      var parts = [
        L.circle([lat, lng], { radius: 1000, color: '#2563eb', weight: 2, fillColor: '#2563eb', fillOpacity: .08, interactive: false }),
        L.circleMarker([lat, lng], { radius: 9, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1 }).bindTooltip('شما اینجا هستید', { permanent: false })
      ];
      if (acc && acc > 30 && acc < 1000) parts.unshift(L.circle([lat, lng], { radius: acc, color: '#2563eb', weight: 1, dashArray: '4 4', fillOpacity: 0, interactive: false }));
      MAP.meLayer = L.layerGroup(parts).addTo(map);
      map.fitBounds(L.latLng(lat, lng).toBounds(2200), { padding: [20, 20] });
      var t = 'موقعیت شما با شعاع ۱ کیلومتر نمایش داده شد';
      if (acc) t += ' (دقت تقریبی ' + fa(Math.round(acc)) + ' متر)';
      t += '.';
      if (acc && acc > 1500) t += ' دقت پایین است (موقعیت از شبکه یا IP گرفته شده)؛ GPS و «موقعیت دقیق» گوشی را روشن کنید و دوباره بزنید.';
      meNote.textContent = t; meNote.hidden = false;
    }
    function resetBtn() { meBtn.disabled = false; clear(meBtn); meBtn.appendChild(h('span', { class: 'ico', 'aria-hidden': 'true' })); meBtn.appendChild(document.createTextNode('موقعیت من')); }
    function locate() {
      if (!navigator.geolocation) { toast('این مرورگر موقعیت‌یابی را پشتیبانی نمی‌کند.'); return; }
      if (root.isSecureContext === false) { meNote.textContent = 'موقعیت‌یابی فقط روی سایت با https کار می‌کند. آدرس صفحه باید با https شروع شود.'; meNote.hidden = false; return; }
      meBtn.disabled = true; meBtn.textContent = 'در حال خواندن GPS…'; meNote.hidden = true;
      var best = null, wid = null, done = false, tm = null;
      function finish() {
        if (done) return; done = true;
        if (wid != null) navigator.geolocation.clearWatch(wid);
        clearTimeout(tm); resetBtn();
        if (best) showMe(best.latitude, best.longitude, best.accuracy);
        else { meNote.textContent = 'موقعیت دریافت نشد. GPS گوشی را روشن کنید و به مرورگر اجازه‌ی «موقعیت مکانی» بدهید.'; meNote.hidden = false; }
      }
      wid = navigator.geolocation.watchPosition(function (p) {
        if (!best || p.coords.accuracy < best.accuracy) best = p.coords;
        if (best.accuracy <= 25) finish();
      }, function (e) {
        if (e.code === 1) { done = true; if (wid != null) navigator.geolocation.clearWatch(wid); clearTimeout(tm); resetBtn(); meNote.textContent = 'دسترسی به موقعیت مسدود است. در تنظیمات مرورگر یا گوشی، اجازه‌ی «موقعیت مکانی» را برای این سایت فعال کنید و دوباره بزنید.'; meNote.hidden = false; }
        else if (!best) { finish(); }
      }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
      tm = setTimeout(finish, 14000);
    }
    drawMarkers();
    if (MAP.pts.length) map.fitBounds(MAP.pts, { padding: [30, 30], maxZoom: 14 });
    setTimeout(function () { map.invalidateSize(); }, 80);
  }

  function syncChips() {
    Array.prototype.forEach.call(ui.timeChips.querySelectorAll('.chip'), function (b) {
      var on = b.dataset.t === S.f.time; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    var tg = S.f.tags ? S.f.tags.split(',') : [];
    if (ui.tagChips) Array.prototype.forEach.call(ui.tagChips.querySelectorAll('.chip'), function (b) {
      var on = tg.indexOf(b.dataset.k) !== -1; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (ui.togs) Array.prototype.forEach.call(ui.togs.querySelectorAll('.chip'), function (b) {
      var on = !!S.f[b.dataset.tg]; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function refreshOptions() {
    if (ADMIN) return refreshOptions0();
    function fill(selEl, name, values, firstLabel) {
      var cur = S.f[name];
      clear(selEl); selEl.appendChild(h('option', { value: '', text: firstLabel }));
      values.forEach(function (v) { selEl.appendChild(h('option', { value: v, text: v })); });
      selEl.value = values.indexOf(cur) !== -1 ? cur : ''; S.f[name] = selEl.value;
    }
    var cats = {}, cities = {};
    S.items.forEach(function (i) { if (i.category) cats[i.category] = 1; if (i.city) cities[i.city] = 1; });
    var fl = function (a, b) { return a.localeCompare(b, 'fa'); };
    fill(ui.cat, 'cat', Object.keys(cats).sort(fl), 'همه دسته‌ها');
    fill(ui.city, 'city', Object.keys(cities).sort(fl), 'همه شهرها');
    dl('tfy-cats', Object.keys(cats).sort(fl));
    var areas = {}; S.items.forEach(function (i) { if (i.area) areas[i.area] = 1; });
    dl('tfy-areas', Object.keys(areas).sort(fl));
    var cs = {}; S.items.forEach(function (i) { if (i.city) cs[i.city] = 1; });
    dl('tfy-cities', Object.keys(cs).sort(fl));
    dl('tfy-names', S.items.map(function (i) { return i.name; }).sort(fl));
    buildExtraFilters();
  }
  function refreshOptions0() {
    var fl = function (a, b) { return a.localeCompare(b, 'fa'); }, c = {}, ci = {}, a = {};
    S.items.forEach(function (i) { if (i.category) c[i.category] = 1; if (i.city) ci[i.city] = 1; if (i.area) a[i.area] = 1; });
    dl('tfy-cats', Object.keys(c).sort(fl)); dl('tfy-cities', Object.keys(ci).sort(fl)); dl('tfy-areas', Object.keys(a).sort(fl));
    dl('tfy-names', S.items.map(function (i) { return i.name; }).sort(fl));
  }
  /* فیلتر ستون‌های دلخواه (از اکسل): اگر مقدارها کم باشند لیست انتخابی، وگرنه جستجوی متنی */
  function buildExtraFilters() {
    if (!ui.filters) return;
    Array.prototype.forEach.call(ui.filters.querySelectorAll('.xf'), function (e) { e.remove(); });
    S.extra.forEach(function (fd) {
      var key = 'x_' + fd.key, vals = {};
      S.items.forEach(function (i) { var v = i.extra && i.extra[fd.key]; if (v) vals[v] = 1; });
      var list = Object.keys(vals).sort(function (a, b) { return a.localeCompare(b, 'fa'); });
      if (!list.length) return;
      var el;
      if (list.length <= 25) {
        EXACT[key] = true;
        el = h('select', { class: 'inp xf', 'aria-label': fd.label, onchange: function () { S.f[key] = el.value; saveFilters(); applyFilters(); } });
        el.appendChild(h('option', { value: '', text: fd.label + ': همه' }));
        list.forEach(function (v) { el.appendChild(h('option', { value: v, text: v })); });
      } else {
        delete EXACT[key];
        el = h('input', { class: 'inp xf', type: 'search', placeholder: fd.label + '…', 'aria-label': fd.label });
        var t; el.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { S.f[key] = el.value; saveFilters(); applyFilters(); }, 160); });
      }
      el.value = S.f[key] || '';
      ui.filters.insertBefore(el, ui.sort);
    });
  }
  function dl(id, values) {
    var el = document.getElementById(id);
    if (!el) { el = h('datalist', { id: id }); document.body.appendChild(el); }
    clear(el); values.forEach(function (v) { el.appendChild(h('option', { value: v })); });
  }

  function applyFilters() {
    if (ADMIN) return;
    S.list = rankItems(S.items, Object.assign({}, S.f, { mineIds: MY.ids, onlyIds: S.only || null }));
    S.shown = 0; S.cards = {}; clear(ui.grid);
    ui.empty.hidden = S.list.length > 0;
    ui.count.textContent = fa(S.list.length) + ' مورد از ' + fa(S.items.length);
    renderMore();
    if (ui.io) { ui.io.unobserve(ui.sentinel); ui.io.observe(ui.sentinel); }
    setTimeout(fillMore, 0);
  }

  function renderMore() {
    var end = Math.min(S.list.length, S.shown + CHUNK), frag = document.createDocumentFragment();
    if ('IntersectionObserver' in root === false) end = S.list.length;
    for (var i = S.shown; i < end; i++) { var el = cardEl(S.list[i]); S.cards[S.list[i].it.id] = el; frag.appendChild(el); }
    S.shown = end; ui.grid.appendChild(frag);
    if (ui.more) ui.more.hidden = S.shown >= S.list.length;
  }
  // اسکرول بی‌نهایت: چند لایه (IntersectionObserver، رویداد اسکرول، و دکمه‌ی «نمایش بیشتر») تا روی هر قالبی کار کند و به آخرین کارت برسد.
  function nearEnd() {
    if (!ui.sentinel || S.shown >= S.list.length) return false;
    var r = ui.sentinel.getBoundingClientRect();
    return r.top < (window.innerHeight || 800) + 1400;
  }
  function fillMore() {
    var guard = 0;
    while (nearEnd() && guard++ < 40) renderMore();
  }
  var scrollT;
  function onScroll() { if (scrollT) return; scrollT = setTimeout(function () { scrollT = 0; if (ui.sentinel) fillMore(); }, 80); }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);

  function hueOf(s) { var x = 0; for (var i = 0; i < s.length; i++) x = (x * 31 + s.charCodeAt(i)) % 360; return x; }
  /**
   * عکس با چند تلاش: ۱) بدون ارسال referrer (حریم خصوصی بهتر)، ۲) با referrer معمولی (برای هاست‌هایی که
   * «محافظ هات‌لینک» دارند و درخواست بدون referrer را رد می‌کنند)، ۳) آدرس درست‌کدشده (فاصله/حروف فارسی)،
   * ۴) ارتقا http به https وقتی صفحه https است. اگر همه شکست خورد onFail صدا زده می‌شود.
   */
  function pic(src, attrs, onFail) {
    var tries = [], stage = 0;
    var img = h('img', Object.assign({ referrerpolicy: 'no-referrer' }, attrs || {}));
    function fixed(u) { try { return new URL(u).href; } catch (e) { return u; } }
    var f = fixed(src);
    tries.push(function () { img.referrerPolicy = 'strict-origin-when-cross-origin'; img.src = src; });
    if (f !== src) tries.push(function () { img.src = f; });
    if (/^http:\/\//i.test(src) && location.protocol === 'https:') tries.push(function () { img.src = f.replace(/^http:/i, 'https:'); });
    img.addEventListener('error', function () {
      if (stage < tries.length) { tries[stage++](); return; }
      onFail && onFail(img);
    });
    img.src = src;
    return img;
  }
  function placeholder(it) {
    return h('div', { class: 'noimg', style: 'background:hsl(' + hueOf(it.category || it.name) + ',45%,88%)', text: (it.name || '؟').charAt(0) });
  }

  function cardEl(r) {
    var it = r.it, ph = h('div', { class: 'ph' });
    if (it.images.length) {
      var img = pic(it.images[0].thumb, { alt: it.name, loading: 'lazy', decoding: 'async' }, function (im) { im.replaceWith(placeholder(it)); });
      ph.appendChild(img);
    } else ph.appendChild(placeholder(it));
    var bd = h('div', { class: 'badges' });
    if (it.favorite) bd.appendChild(h('span', { class: 'bdg fav', text: 'علاقه‌مندی' }));
    if (it.done) bd.appendChild(h('span', { class: 'bdg done', text: 'انجام شد' }));
    if (CAN) { var mc = missingOf(it); if (mc.length) bd.appendChild(h('span', { class: 'bdg miss', title: mc.map(function (x) { return x.t; }).join('\n'), text: 'ناقص: ' + fa(mc.length) })); }
    ph.appendChild(bd);
    var heart = h('button', { class: 'heart' + (inMy(it.id) ? ' on' : ''), type: 'button', 'aria-label': 'افزودن به لیست من', text: inMy(it.id) ? '♥' : '♡', onclick: function (e) {
      e.stopPropagation(); var on = toggleMy(it.id); heart.textContent = on ? '♥' : '♡'; heart.classList.toggle('on', on); } });
    heart.addEventListener('keydown', function (e) { e.stopPropagation(); });
    ph.appendChild(heart);
    var tags = h('div', { class: 'tags' }, h('span', { class: 'tag t-dur', text: durLabel(it.duration_h) }));
    it.times.forEach(function (t) { tags.appendChild(h('span', { class: 'tag', text: t })); });
    var cst = costOf(it);
    if (cst != null) tags.appendChild(h('span', { class: 'tag t-cost', text: cst === 0 ? 'رایگان' : fa(cst) + ' تومان' }));
    it.modes.forEach(function (m) { tags.appendChild(h('span', { class: 'tag', text: MODES[m] })); });
    if (parseHours(it.hours).open === true) tags.appendChild(h('span', { class: 'tag t-open', text: 'باز است' }));
    r.reasons.forEach(function (x) { tags.appendChild(h('span', { class: 'tag t-weak', text: x })); });
    var el = h('div', { class: 'card', role: 'button', tabindex: '0', 'aria-label': it.name },
      ph, h('div', { class: 'body' }, h('div', { class: 'cat', text: it.category }), h('h3', { text: it.name }), h('p', { class: 'desc', text: it.description }), tags));
    el.addEventListener('click', function () { openDetail(it.id); });
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDetail(it.id); } });
    return el;
  }

  function replaceItem(n) {
    var i = S.items.findIndex(function (x) { return x.id === n.id; });
    if (i === -1) S.items.push(n); else S.items[i] = n;
    for (var j = 0; j < S.list.length; j++) {
      if (S.list[j].it.id === n.id) {
        S.list[j].it = n;
        var old = S.cards[n.id];
        if (old) { var el = cardEl(S.list[j]); old.replaceWith(el); S.cards[n.id] = el; }
        break;
      }
    }
  }
  function getItem(id) { return S.items.find(function (x) { return x.id === id; }); }

  /* ---------- تصادفی ---------- */
  function pickRandom() {
    var pool = S.list.filter(function (x) { return x.tier === 0 && !x.it.done; });
    if (!pool.length) pool = S.list.filter(function (x) { return x.tier === 0; });
    if (!pool.length) pool = S.list;
    if (!pool.length) { toast('با فیلترهای فعلی موردی وجود ندارد.'); return; }
    var size = pool.length;
    if (pool.length > 1) pool = pool.filter(function (x) { return x.it.id !== S.lastRandom; });
    var pick = pool[Math.floor(Math.random() * pool.length)].it;
    S.lastRandom = pick.id;
    openDetail(pick.id, { random: size });
  }

  /* ---------- لیست من، اشتراک، رویدادها ---------- */
  var MY = { ids: [] };
  try { var _m = JSON.parse(localStorage.getItem('tfy_my') || '[]'); if (Array.isArray(_m)) MY.ids = _m.filter(function (x) { return typeof x === 'number'; }); } catch (e) {}
  function saveMy() { try { localStorage.setItem('tfy_my', JSON.stringify(MY.ids)); } catch (e) {} updMyBtn(); }
  function inMy(id) { return MY.ids.indexOf(id) !== -1; }
  function toggleMy(id) { var i = MY.ids.indexOf(id); if (i === -1) MY.ids.push(id); else MY.ids.splice(i, 1); saveMy(); if (S.f.mine) applyFilters(); return i === -1; }
  function updMyBtn() { if (ui.myBtn) ui.myBtn.textContent = 'لیست من' + (MY.ids.length ? ' (' + fa(MY.ids.length) + ')' : ''); }
  function track(type, id, ref) { if (CAN || ADMIN || MANAGE) return; try { api('POST', 'event', { type: type, id: id, ref: ref || 0 }).catch(function () {}); } catch (e) {} }
  function pageUrl(params) {
    var u = new URL(location.href);
    ['card', 'list', 'plan', 't', 'p', 'o'].forEach(function (k) { u.searchParams.delete(k); });
    for (var k in (params || {})) if (params[k] !== '') u.searchParams.set(k, params[k]);
    u.hash = ''; return u.toString();
  }
  function shareLink(url, title) {
    if (navigator.share) return navigator.share({ title: title, url: url }).catch(function () {});
    try { return navigator.clipboard.writeText(url).then(function () { toast('لینک کپی شد'); }); } catch (e) { toast(url); }
  }
  function people() { try { var n = +localStorage.getItem('tfy_people'); return n >= 1 && n <= 30 ? n : 2; } catch (e) { return 2; } }
  function setPeople(n) { try { localStorage.setItem('tfy_people', String(n)); } catch (e) {} }

  /* ---------- هوا ---------- */
  S.weather = null;
  function loadWeather() {
    if (!CFG.weather) return;
    api('GET', 'weather').then(function (w) { if (w && w.ok) { S.weather = w; drawWeather(); } }).catch(function () {});
  }
  function drawWeather() {
    if (!ui.weather || !S.weather) return;
    var w = S.weather; clear(ui.weather); ui.weather.hidden = false; ui.weather.classList.toggle('wet', !!w.rainy);
    add(ui.weather, [h('span', { text: 'هوای تهران: ' + fa(w.temp) + ' درجه، ' + w.label + (w.rain_prob ? ' (احتمال بارش ' + fa(w.rain_prob) + '٪)' : '') }),
      w.rainy ? h('button', { class: 'btn btn-sm', type: 'button', text: 'فقط سرپوشیده‌ها', onclick: function () { S.f.tags = 'indoor'; saveFilters(); syncChips(); applyFilters(); } }) : null]);
  }

  /* ---------- گزارش اشتباه ---------- */
  var REPORT_FIELDS = [['price', 'قیمت'], ['hours', 'ساعت کاری'], ['location', 'لوکیشن'], ['phone', 'تلفن'], ['website', 'وب‌سایت'], ['image', 'عکس'], ['other', 'مورد دیگر']];
  function openReport(it) {
    var fsel = h('select', { class: 'inp' }, REPORT_FIELDS.map(function (o) { return h('option', { value: o[0], text: o[1] }); }));
    var note = h('textarea', { class: 'inp', rows: 3, maxlength: 300, placeholder: 'چه چیزی اشتباه است؟ (اختیاری)' });
    var hp = h('input', { type: 'text', tabindex: '-1', autocomplete: 'off', style: 'position:absolute;left:-9999px;opacity:0', 'aria-hidden': 'true' });
    var msg = h('div', { class: 'hint' });
    openModal(function (m) {
      var send = h('button', { class: 'btn btn-primary', type: 'button', text: 'ارسال گزارش', onclick: function () {
        send.disabled = true;
        api('POST', 'items/' + it.id + '/report', { field: fsel.value, note: note.value, hp: hp.value }).then(function () { m.close(); toast('ممنون؛ گزارش شما ثبت شد.'); })
          .catch(function (e) { msg.textContent = e.message; send.disabled = false; });
      } });
      return [head('گزارش اشتباه درباره‌ی «' + it.name + '»', m), h('div', { class: 'mb' }, h('label', { class: 'lbl', text: 'کدام بخش؟' }), fsel, h('label', { class: 'lbl', text: 'توضیح' }), note, hp, msg),
        h('div', { class: 'mf' }, h('span', { class: 'sp' }), send)];
    }, { small: true });
  }

  /* ---------- برنامه‌ی روز ---------- */
  function two(n) { return (n < 10 ? '0' : '') + n; }
  function fmtMin(m) { var d = Math.floor(m / 1440); return fa(two(Math.floor(m / 60) % 24)) + ':' + fa(two(m % 60)) + (d ? ' (فردا)' : ''); }
  function todayTehran() { try { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Tehran' }).format(new Date()); } catch (e) { return new Date().toISOString().slice(0, 10); } }
  function nextSlot() { var n = tehranNow(); var m = Math.ceil((n.min + 30) / 30) * 30; return Math.min(m, 22 * 60); }
  function toIcs(plan, dateStr, link) {
    var p = dateStr.split('-').map(Number), base = Date.UTC(p[0], p[1] - 1, p[2], 0, 0, 0);
    function f(min) { var d = new Date(base + min * 60000); return d.getUTCFullYear() + two(d.getUTCMonth() + 1) + two(d.getUTCDate()) + 'T' + two(d.getUTCHours()) + two(d.getUTCMinutes()) + '00'; }
    function esc(s) { return String(s || '').replace(/[\;,]/g, function (c) { return '\\' + c; }).replace(/\n/g, '\\n'); }
    var out = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Tafrihyab//FA//EN', 'CALSCALE:GREGORIAN'];
    plan.stops.forEach(function (s, i) {
      out.push('BEGIN:VEVENT', 'UID:tfy-' + s.it.id + '-' + base + '-' + i + '@tafrihyab', 'DTSTAMP:' + f(0).slice(0, 8) + 'T000000', 'DTSTART;TZID=Asia/Tehran:' + f(s.start), 'DTEND;TZID=Asia/Tehran:' + f(s.end),
        'SUMMARY:' + esc(s.it.name), 'LOCATION:' + esc(s.it.address || s.it.area || s.it.city), 'DESCRIPTION:' + esc(link), 'END:VEVENT');
    });
    out.push('END:VCALENDAR');
    return out.join('\r\n');
  }
  function openPlan(ids, o) {
    o = o || {};
    var items = ids.map(getItem).filter(Boolean).slice(0, 6);
    if (!items.length) { toast('موردی برای برنامه نیست.'); return; }
    var t0 = o.t && /^\d{4}$/.test(o.t) ? (+o.t.slice(0, 2)) * 60 + (+o.t.slice(2)) : nextSlot();
    var tIn = h('input', { class: 'inp', type: 'time', value: two(Math.floor(t0 / 60) % 24) + ':' + two(t0 % 60) });
    var pIn = h('input', { class: 'inp', type: 'number', min: 1, max: 30, value: o.p || people() });
    var dIn = h('input', { class: 'inp', type: 'date', value: todayTehran() });
    var out = h('div', { class: 'plan' });
    var plan = null;
    function startMin() { var v = (tIn.value || '16:00').split(':'); return (+v[0]) * 60 + (+v[1]); }
    function draw() {
      var n = Math.max(1, +pIn.value || 1); setPeople(n);
      plan = buildPlan(items, startMin(), n, new Date(), !!o.fixed);
      clear(out);
      plan.stops.forEach(function (s, i) {
        if (i) out.appendChild(h('div', { class: 'ptravel', text: 'رفت‌وآمد حدود ' + fa(plan.stops[i - 1].travel) + ' دقیقه (تخمینی)' }));
        var ec = entryCost(s.it);
        out.appendChild(h('div', { class: 'pstop' + (s.warn ? ' warn' : '') },
          h('div', { class: 'pt', text: fmtMin(s.start) + ' تا ' + fmtMin(s.end) }),
          h('div', { class: 'pn' }, h('a', { href: '#', text: s.it.name, onclick: function (e) { e.preventDefault(); openDetail(s.it.id); } }), h('span', { class: 'ps', text: (s.it.area || s.it.city || '') + (ec != null ? ' — ورودی ' + (ec === 0 ? 'رایگان' : fa(ec) + ' تومان برای هر نفر') : '') })),
          s.warn ? h('div', { class: 'pw', text: 'طبق ساعت ثبت‌شده در این زمان ممکن است بسته باشد' }) : null));
      });
      out.appendChild(h('div', { class: 'psum', text: 'جمع ورودی‌های معلوم برای ' + fa(n) + ' نفر: ' + fa(plan.total) + ' تومان' + (plan.unknown ? ' (' + fa(plan.unknown) + ' مورد بدون قیمت)' : '') + ' — بدون پارکینگ، غذا و هزینه‌های فرعی. پایان برنامه حدود ' + fmtMin(plan.end) + '.' }));
    }
    [tIn, pIn, dIn].forEach(function (e) { e.addEventListener('change', draw); });
    openModal(function (m) {
      return [head('برنامه‌ی روز', m), h('div', { class: 'mb' },
        h('div', { class: 'fgrid' }, h('div', {}, h('label', { class: 'lbl', text: 'ساعت شروع' }), tIn), h('div', {}, h('label', { class: 'lbl', text: 'تعداد نفرات' }), pIn), h('div', {}, h('label', { class: 'lbl', text: 'تاریخ (برای تقویم)' }), dIn)),
        out),
        h('div', { class: 'mf' },
          h('button', { class: 'btn', type: 'button', text: 'اشتراک برنامه', onclick: function () {
            var u = pageUrl({ plan: items.map(function (x) { return x.id; }).join(','), t: two(Math.floor(startMin() / 60)) + two(startMin() % 60), p: Math.max(1, +pIn.value || 1), o: o.fixed ? 1 : '' });
            track('share', items[0].id); shareLink(u, 'برنامه‌ی روز'); } }),
          h('button', { class: 'btn', type: 'button', text: 'دانلود برای تقویم (ics)', onclick: function () {
            var blob = new Blob([toIcs(plan, dIn.value || todayTehran(), pageUrl({ plan: items.map(function (x) { return x.id; }).join(',') }))], { type: 'text/calendar;charset=utf-8' });
            var a = h('a', { href: URL.createObjectURL(blob), download: 'tafrihyab-plan.ics' }); document.body.appendChild(a); a.click(); a.remove();
          } }))];
    });
    draw();
  }

  /* ---------- لیست من ---------- */
  function openMyList() {
    var chosen = {};
    MY.ids.slice(0, 6).forEach(function (id) { chosen[id] = true; });
    openModal(function (m) {
      var items = MY.ids.map(getItem).filter(Boolean);
      var body = items.length ? items.map(function (it) {
        return h('div', { class: 'myrow' },
          h('input', { type: 'checkbox', checked: !!chosen[it.id], 'aria-label': 'انتخاب برای برنامه', onchange: function () { if (this.checked) chosen[it.id] = true; else delete chosen[it.id]; } }),
          h('a', { href: '#', class: 'mn', text: it.name, onclick: function (e) { e.preventDefault(); openDetail(it.id); } }),
          h('span', { class: 'ms', text: it.area || it.city || '' }),
          h('button', { class: 'btn btn-sm', type: 'button', text: 'حذف', onclick: function () { toggleMy(it.id); delete chosen[it.id]; m.rebuild(); } }));
      }) : h('div', { class: 'empty', text: 'لیست شما خالی است. روی ♡ کارت‌ها بزنید.' });
      return [head('لیست من', m), h('div', { class: 'mb' }, h('p', { class: 'hint', text: 'این لیست فقط روی همین دستگاه ذخیره می‌شود. با «اشتراک لیست» می‌توانید آن را برای دیگران بفرستید. برای ساخت برنامه‌ی روز، حداکثر ۶ مورد را تیک بزنید.' }), body),
        items.length ? h('div', { class: 'mf' },
          h('button', { class: 'btn btn-primary', type: 'button', text: 'ساخت برنامه‌ی روز', onclick: function () { var ids = MY.ids.filter(function (id) { return chosen[id]; }); if (!ids.length) { toast('حداقل یک مورد را تیک بزنید.'); return; } m.close(); openPlan(ids); } }),
          h('button', { class: 'btn', type: 'button', text: 'اشتراک لیست', onclick: function () { track('share', items[0].id); shareLink(pageUrl({ list: MY.ids.join(',') }), 'لیست تفریحات من'); } }),
          h('button', { class: 'btn', type: 'button', text: 'نمایش در صفحه اصلی', onclick: function () { S.f.mine = '1'; S.only = null; saveFilters(); syncChips(); applyFilters(); m.close(); } })) : null];
    });
  }

  /* ---------- «امشب چه کنیم؟» ---------- */
  function openWizard() {
    var ppl = h('input', { class: 'inp', type: 'number', min: 1, max: 30, value: people() });
    var fam = h('input', { type: 'checkbox' });
    var bud = h('input', { class: 'inp', type: 'number', min: 0, step: 10000, placeholder: 'مثلا ۳۰۰۰۰۰ (خالی = مهم نیست)' });
    var hrs = h('select', { class: 'inp' }, [[6, 'تا نیم‌روز'], [3, 'تا ۳ ساعت'], [1, 'تا ۱ ساعت'], [12, 'یک روز کامل']].map(function (o) { return h('option', { value: o[0], text: o[1] }); }));
    var nowH = Math.floor(tehranNow().min / 60);
    var tm = h('select', { class: 'inp' }, [['', 'مهم نیست']].concat(['صبح', 'ظهر', 'عصر', 'شب'].map(function (x) { return [x, x]; })).map(function (o) { return h('option', { value: o[0], text: o[1] }); }));
    tm.value = nowH < 11 ? 'صبح' : nowH < 15 ? 'ظهر' : nowH < 19 ? 'عصر' : 'شب';
    var cities = {}; S.items.forEach(function (i) { if (i.city) cities[i.city] = 1; });
    var cs = h('select', { class: 'inp' }, [h('option', { value: '', text: 'همه شهرها' })].concat(Object.keys(cities).sort(function (a, b) { return a.localeCompare(b, 'fa'); }).map(function (c) { return h('option', { value: c, text: c }); })));
    var opn = h('input', { type: 'checkbox' });
    var res = h('div', { class: 'wres' });
    var all = [], off = 0, used = [], route = null;
    function lbl(t, el) { return h('div', {}, h('label', { class: 'lbl', text: t }), el); }
    function stopCard(it, why, title, story) {
      return h('div', { class: 'wcard' },
        it.images.length ? pic(it.images[0].thumb, { alt: '', loading: 'lazy' }, function (im) { im.remove(); }) : null,
        h('div', { class: 'wb' }, h('div', { class: 'wn', text: title }), story ? h('div', { class: 'wstory', text: story }) : null,
          h('div', { class: 'chips' }, why.map(function (w) { return h('span', { class: 'tag', text: w }); })),
          h('div', { class: 'links' }, h('button', { class: 'btn btn-sm btn-primary', type: 'button', text: 'باز کردن', onclick: function () { openDetail(it.id); } }),
            h('button', { class: 'btn btn-sm', type: 'button', text: inMy(it.id) ? 'در لیست من ✓' : 'افزودن به لیست من', onclick: function () { toggleMy(it.id); draw(); } }))));
    }
    function draw() {
      clear(res);
      if (route) {
        var st = route.stops, story = routeStory(route);
        res.appendChild(h('div', { class: 'wroute' },
          h('strong', { text: st.length > 1 ? 'سناریوی پیشنهادی: ' + fa(st.length) + ' مکان در یک مسیر' : 'فقط یک مکان در این زمان جا می‌شود' }),
          h('div', { class: 'hint', text: (st.length > 1 ? 'مسیر کلی ' + kmLabel(route.km) + '، حدود ' + fa(Math.round(route.minutes / 6) / 10) + ' ساعت با رفت‌وآمد. ' : 'برای ترکیب چند مکان، زمان را بیشتر کنید. ') + 'فاصله‌ها خط مستقیم روی نقشه‌اند و مسیر واقعی خیابان‌ها بیشتر است.' })));
        st.forEach(function (x, i) { res.appendChild(stopCard(x.it, x.why, fa(i + 1) + '. ' + x.it.name, story[i])); });
        res.appendChild(h('div', { class: 'links' },
          st.length > 1 ? h('button', { class: 'btn btn-primary', type: 'button', text: 'برنامه‌ی ساعتی این مسیر', onclick: function () { openPlan(st.map(function (x) { return x.it.id; }), { fixed: true }); } }) : null,
          h('button', { class: 'btn', type: 'button', text: 'مسیر دیگر', onclick: function () { used.push(route.seed); route = planRoute(all, ropt(), rctx()); if (!route) { used = []; route = planRoute(all, ropt(), rctx()); } draw(); } })));
        return;
      }
      var part = all.slice(off, off + 5);
      if (!part.length) { off = 0; part = all.slice(0, 5); }
      res.appendChild(h('div', { class: 'hint', text: fa(all.length) + ' گزینه مناسب پیدا شد؛ برای ساختن مسیر به حداقل دو مکان دارای لوکیشن روی نقشه نیاز است، پس فهرست ساده نشان داده می‌شود.' }));
      part.forEach(function (r, i) { res.appendChild(stopCard(r.it, r.why, fa(off + i + 1) + '. ' + r.it.name)); });
      res.appendChild(h('div', { class: 'links' }, h('button', { class: 'btn', type: 'button', text: 'پیشنهادهای بعدی', onclick: function () { off += 5; draw(); } }),
        h('button', { class: 'btn', type: 'button', text: 'دوباره بچین', onclick: function () { run(true); } })));
    }
    function ropt() { return { hours: +hrs.value }; }
    function rctx() { return { avoid: used, jitter: function () { return Math.random() * 4; } }; }
    function run(fresh) {
      if (fresh) {
        setPeople(Math.max(1, +ppl.value || 1));
        all = suggest(S.items, { people: +ppl.value || 1, family: fam.checked, budget: +bud.value || 0, hours: +hrs.value, open: opn.checked, time: tm.value, city: cs.value },
          { rainy: !!(S.weather && S.weather.rainy), date: new Date(), jitter: function () { return Math.random() * 3; } });
        off = 0; used = []; route = all.length ? planRoute(all, ropt(), rctx()) : null;
      }
      if (!all.length) { clear(res); res.appendChild(h('div', { class: 'empty', text: 'با این شرایط موردی پیدا نشد. بودجه یا مدت را بازتر کنید.' })); return; }
      draw();
    }
    openModal(function (m) {
      return [head('امشب چه کنیم؟', m), h('div', { class: 'mb' },
        h('div', { class: 'fgrid' }, lbl('تعداد نفرات', ppl), lbl('بودجه برای هر نفر (تومان)', bud), lbl('چقدر وقت دارید؟', hrs), lbl('چه موقع؟', tm), lbl('شهر', cs),
          h('div', { class: 'full' }, h('label', { class: 'chk' }, fam, ' با کودک یا خانوادگی'), ' ', h('label', { class: 'chk' }, opn, ' فقط جاهایی که الان باز است'))),
        h('div', { class: 'links' }, h('button', { class: 'btn btn-primary', type: 'button', 'data-autofocus': '', text: 'پیشنهاد بده', onclick: function () { run(true); } })), res)];
    });
  }

  /* ---------- جزئیات ---------- */
  function qaBox(it) {
    if (!CFG.qa) return null;
    var inp = h('input', { class: 'inp', type: 'text', maxlength: 300, placeholder: 'درباره‌ی «' + it.name + '» بپرسید…' });
    var hp = h('input', { type: 'text', tabindex: '-1', autocomplete: 'off', style: 'position:absolute;left:-9999px;opacity:0', 'aria-hidden': 'true' });
    var out = h('div', { class: 'qa-out', hidden: true, 'aria-live': 'polite' });
    var btn = h('button', { class: 'btn btn-primary btn-sm', type: 'button', text: 'بپرس' });
    function ask(q) {
      q = (q || inp.value).trim(); if (q.length < 3) { out.hidden = false; out.className = 'qa-out err'; out.textContent = 'سوال را کامل‌تر بنویسید.'; return; }
      inp.value = q; btn.disabled = true; btn.textContent = 'در حال پاسخ…'; out.hidden = false; out.className = 'qa-out'; out.textContent = '…';
      api('POST', 'items/' + it.id + '/ask', { q: q, hp: hp.value }).then(function (r) {
        out.className = 'qa-out ok'; out.textContent = r.answer;
        out.appendChild(h('div', { class: 'hint', text: 'پاسخ هوش مصنوعی و فقط بر پایه‌ی اطلاعات همین کارت است؛ قیمت و ساعت را قبل از رفتن چک کنید.' }));
      }).catch(function (e) { out.className = 'qa-out err'; out.textContent = e.message; })
        .then(function () { btn.disabled = false; btn.textContent = 'بپرس'; });
    }
    btn.addEventListener('click', function () { ask(); });
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); ask(); } });
    var sug = ['ساعت کاری و هزینه‌اش چقدره؟', 'برای خانواده مناسبه؟', 'چقدر وقت لازم دارم؟'];
    return h('div', { class: 'qa' }, h('div', { class: 'cbt', text: 'سوالی درباره‌ی این مورد دارید؟' }),
      h('div', { class: 'qa-row' }, inp, btn, hp),
      h('div', { class: 'chips' }, sug.map(function (s) { return h('button', { class: 'chip', type: 'button', text: s, onclick: function () { ask(s); } }); })), out);
  }

  function openDetail(id, opts) {
    opts = opts || {};
    var curId = id, rnd = opts.random || 0, trail = [];
    var gi = 0, lastTracked = 0, mm = null;
    function posOf() { for (var i = 0; i < S.list.length; i++) { if (S.list[i].it.id === curId) return i; } return -1; }
    function step(d) {
      var p = posOf(), n = p + d;
      if (p < 0 || n < 0 || n >= S.list.length || !mm) return;
      curId = S.list[n].it.id; trail = []; gi = 0; mm.rebuild(); mm.el.scrollTop = 0;
    }
    // در صفحه‌ی راست‌به‌چپ، «بعدی» سمت چپ است: کلید چپ = بعدی، کلید راست = قبلی
    function onKey(e) {
      if (!mm || stack.indexOf(mm) === -1) { document.removeEventListener('keydown', onKey); return; }
      if (stack[stack.length - 1] !== mm || e.ctrlKey || e.metaKey || e.altKey || e.shiftKey) return;
      var t = e.target || {};
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable) return;
      if (e.key === 'ArrowLeft') { e.preventDefault(); step(1); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); step(-1); }
    }
    document.addEventListener('keydown', onKey);
    mm = openModal(function (m) {
      var it = getItem(curId);
      if (!it) { m.close(); return []; }
      if (lastTracked !== curId) { lastTracked = curId; track('view', curId); }
      if (gi >= it.images.length) gi = 0;
      var gal = h('div', { class: 'gal' });
      if (it.images.length) {
        var im = pic(it.images[gi].full, { alt: it.name }, function (x) { x.replaceWith(placeholder(it)); });
        gal.appendChild(im);
        if (it.images.length > 1) {
          gal.appendChild(h('button', { class: 'nav prev', type: 'button', 'aria-label': 'قبلی', text: '›', onclick: function () { gi = (gi - 1 + it.images.length) % it.images.length; m.rebuild(); } }));
          gal.appendChild(h('button', { class: 'nav next', type: 'button', 'aria-label': 'بعدی', text: '‹', onclick: function () { gi = (gi + 1) % it.images.length; m.rebuild(); } }));
          gal.appendChild(h('div', { class: 'cnt', text: fa(gi + 1) + ' / ' + fa(it.images.length) }));
        }
      } else gal.appendChild(placeholder(it));

      function row(k, v, link) {
        if (!v) return null;
        var val = link ? h('a', { href: link, target: '_blank', rel: 'noopener noreferrer', text: v }) : v;
        return h('div', { class: 'row' }, h('div', { class: 'k', text: k }), h('div', { class: 'v' }, val));
      }
      function price(p) { return p == null ? 'نامشخص' : (p === 0 ? 'رایگان' : fa(p) + ' تومان'); }
      function costBlock(title, list) {
        if (!list || !list.length) return null;
        return h('div', { class: 'cb' }, h('div', { class: 'cbt', text: title }), list.map(function (c) {
          return h('div', { class: 'cr' }, h('span', { class: 'cl2', text: c.label }), h('span', { class: 'cp' + (c.price === 0 ? ' free' : ''), text: price(c.price) }), c.note ? h('span', { class: 'cn', text: c.note }) : null);
        }));
      }
      function menuBlock(menu) {
        if (!menu || !menu.length) return null;
        var groups = {}, order = [];
        menu.forEach(function (x) { var k = x.cat || 'منو'; if (!groups[k]) { groups[k] = []; order.push(k); } groups[k].push(x); });
        return h('details', { class: 'menu' }, h('summary', { text: 'منو و قیمت‌ها (' + fa(menu.length) + ' مورد)' }), order.map(function (k) {
          return [h('div', { class: 'cbt', text: k }), groups[k].map(function (x) { return h('div', { class: 'cr' }, h('span', { class: 'cl2', text: x.name }), h('span', { class: 'cp', text: price(x.price) })); })];
        }));
      }
      var cost = it.cost_note;
      var rows = h('div', { class: 'rows' },
        row('مدت', durLabel(it.duration_h)), row('زمان مناسب', it.times.join('، ')),
        row('مناسب برای', it.modes.map(function (x) { return MODES[x]; }).join('، ')),
        row('هزینه', cost), row('شهر', it.city), row('محدوده', it.area), row('آدرس', it.address), row('ساعت کاری', it.hours),
        row('تلفن', it.phone, it.phone ? 'tel:' + it.phone.replace(/[^\d+]/g, '') : ''),
        S.extra.map(function (fd) { return row(fd.label, it.extra && it.extra[fd.key]); }));
      var priceDate = it.prices_at ? h('div', { class: 'hint', text: 'قیمت‌ها و منو: ' + new Date(it.prices_at * 1000).toLocaleDateString('fa-IR') + (it.prices_src === 'ai' ? ' — از جستجوی خودکار؛ قبل از رفتن چک کنید' : ' — ثبت دستی') + (it.price_review ? ' · آخرین بازبینی: ' + new Date(it.price_review * 1000).toLocaleDateString('fa-IR') : '') }) : null;
      var oh = parseHours(it.hours);
      var openB = oh.open === null ? null : h('div', { class: 'openb ' + (oh.open ? 'yes' : 'no'), text: (oh.open ? 'الان باز است' : 'الان بسته است') + ' (طبق ساعت کاری ثبت‌شده)' });
      var missBox = null;
      if (CAN) {
        var ml = missingOf(it);
        missBox = ml.length ? h('div', { class: 'missbox' },
          h('div', { class: 'cbt', text: 'موارد ناقص این کارت (' + fa(ml.length) + ')' }),
          h('ul', {}, ml.map(function (x) { return h('li', { class: x.w === 2 ? 'imp' : '' }, (x.w === 2 ? 'مهم: ' : 'کم‌اهمیت: ') + x.t); })),
          h('div', { class: 'links' },
            h('button', { class: 'btn btn-sm btn-primary', type: 'button', text: 'ویرایش و تکمیل', onclick: function () { m.close(); openForm(getItem(curId), MANAGE ? { manage: true } : {}); } }),
            S.ai ? h('span', { class: 'hint', text: 'یا با «تکمیل خودکار» پایین صفحه پر شود (فقط خالی‌ها).' }) : null))
          : h('div', { class: 'missbox ok', text: 'این کارت کامل است.' });
      }
      var tagRow = it.tags && it.tags.length ? h('div', { class: 'chips tagrow' }, it.tags.map(function (k) { return h('span', { class: 'tag t-feat', text: TAGS[k] || k }); })) : null;
      var ecost = entryCost(it), pplBox = null;
      if (ecost != null) {
        var pn = people(), pv = h('strong'), pin = h('span', { class: 'pn', text: fa(pn) });
        var upd = function () { pv.textContent = ecost === 0 ? 'رایگان' : fa(ecost * pn) + ' تومان'; pin.textContent = fa(pn); setPeople(pn); };
        pplBox = h('div', { class: 'ppl' }, h('span', { text: 'ورودی برای ' }),
          h('button', { class: 'btn btn-sm', type: 'button', 'aria-label': 'کمتر', text: '−', onclick: function () { if (pn > 1) { pn--; upd(); } } }), pin,
          h('button', { class: 'btn btn-sm', type: 'button', 'aria-label': 'بیشتر', text: '+', onclick: function () { if (pn < 30) { pn++; upd(); } } }),
          h('span', { text: ' نفر: ' }), pv, h('span', { class: 'hint', text: ' (تقریبی؛ بدون پارکینگ، غذا و هزینه‌های فرعی)' }));
        upd();
      }
      var costs = (it.costs && it.costs.length) || (it.costs_extra && it.costs_extra.length) || (it.menu && it.menu.length)
        ? h('div', { class: 'costs' }, costBlock('هزینه‌های اصلی', it.costs), costBlock('هزینه‌های فرعی و پیشنهادی', it.costs_extra), menuBlock(it.menu), priceDate) : null;
      var rel = it.related && it.related.length ? h('div', { class: 'rel' }, h('div', { class: 'cbt', text: 'تفریحات تکمیلی' }), h('div', { class: 'chips' }, it.related.map(function (r) {
        return r.id ? h('button', { class: 'chip rl', type: 'button', text: r.name, onclick: function () { trail.push(curId); curId = r.id; gi = 0; m.rebuild(); m.el.scrollTop = 0; } })
          : h('span', { class: 'chip rl dis', title: 'این مورد در لیست نیست', text: r.name });
      }))) : null;
      var links = h('div', { class: 'links' });
      if (safeUrl(it.location)) links.appendChild(h('a', { class: 'btn btn-primary', href: it.location, target: '_blank', rel: 'noopener noreferrer', text: 'مسیریابی با نشان' }));
      if (safeUrl(it.website)) links.appendChild(h('a', { class: 'btn', href: it.website, target: '_blank', rel: 'noopener noreferrer', text: 'وب‌سایت / صفحه رسمی' }));

      var notices = [];
      if (it.guess_modes) notices.push(h('div', { class: 'notice', text: 'حالت‌های «همراه» برای این مورد پیشنهادی (حدسی یا از هوش مصنوعی) است و هنوز بازبینی نشده. از دکمه ویرایش اصلاحش کنید.' }));
      if (it.enriched_at) {
        var src = h('div', { class: 'src' });
        it.sources.forEach(function (u, i) { if (safeUrl(u)) { if (i) src.appendChild(document.createTextNode(' | ')); src.appendChild(h('a', { href: u, target: '_blank', rel: 'noopener noreferrer', text: hostOf(u) })); } });
        notices.push(h('div', { class: 'notice info' }, 'بخشی از اطلاعات این مورد با جستجوی خودکار تکمیل شده و بررسی نشده است؛ قیمت و ساعت کاری را قبل از رفتن چک کنید. ', it.sources.length ? [h('br'), 'منابع: ', src] : null));
      }

      var favBtn = h('button', { class: 'btn' + (it.favorite ? ' on' : ''), type: 'button', text: it.favorite ? 'علاقه‌مندی ✓' : 'علاقه‌مندی', onclick: function () { quick(it, { favorite: !it.favorite }, m); } });
      var doneBtn = h('button', { class: 'btn' + (it.done ? ' on' : ''), type: 'button', text: it.done ? 'انجام شد ✓' : 'انجام شد', onclick: function () { quick(it, { done: !it.done }, m); } });
      var aiBtn = CAN && S.ai ? h('button', { class: 'btn', type: 'button', text: 'تکمیل خودکار', onclick: function () {
        aiBtn.disabled = true; aiBtn.textContent = 'در حال جستجو (تا ۲ دقیقه)…';
        api('POST', 'enrich', { id: it.id, save: true }).then(function (r) { replaceItem(r.item); if (!MANAGE) refreshOptions(); toast(r.filled && r.filled.length ? 'تکمیل شد: ' + r.filled.join('، ') : 'چیز تازه‌ای پیدا نشد.'); m.rebuild(); })
          .catch(function (e) { toast(e.message); aiBtn.disabled = false; aiBtn.textContent = 'تکمیل خودکار'; });
      } }) : null;

      var pos = posOf();
      var pnBar = pos >= 0 && S.list.length > 1 ? h('div', { class: 'pnbar' },
        h('button', { class: 'btn btn-sm', type: 'button', text: 'قبلی', disabled: pos === 0, onclick: function () { step(-1); } }),
        h('span', { text: fa(pos + 1) + ' از ' + fa(S.list.length) + ' (می‌توانید از کلیدهای جهت‌دار صفحه‌کلید هم استفاده کنید)' }),
        h('button', { class: 'btn btn-sm', type: 'button', text: 'بعدی', disabled: pos === S.list.length - 1, onclick: function () { step(1); } })) : null;
      return [
        pnBar,
        trail.length ? h('div', { class: 'rndbar' }, h('span', { text: 'از «' + ((getItem(trail[trail.length - 1]) || {}).name || '') + '»' }),
          h('button', { class: 'btn btn-sm', type: 'button', text: 'بازگشت', onclick: function () { curId = trail.pop(); gi = 0; m.rebuild(); m.el.scrollTop = 0; } })) : null,
        rnd && !trail.length ? h('div', { class: 'rndbar' }, h('span', { text: 'پیشنهاد تصادفی از بین ' + fa(rnd) + ' مورد' }),
          h('button', { class: 'btn btn-sm', type: 'button', text: 'یکی دیگر', onclick: function () {
            var pool = S.list.filter(function (x) { return x.tier === 0 && !x.it.done; });
            if (!pool.length) pool = S.list.filter(function (x) { return x.tier === 0; });
            if (!pool.length) pool = S.list;
            if (pool.length > 1) pool = pool.filter(function (x) { return x.it.id !== curId; });
            curId = pool[Math.floor(Math.random() * pool.length)].it.id; S.lastRandom = curId; gi = 0; m.rebuild();
          } })) : null,
        head(it.name, m), gal,
        h('div', { class: 'mb dt' },
          h('div', { class: 'sub', text: it.category }),
          it.description ? h('p', { class: 'd', text: it.description }) : null,
          missBox, openB, tagRow, rows, pplBox, costs, qaBox(it), rel, links, h('div', { class: 'links usr' },
            h('button', { class: 'btn' + (inMy(it.id) ? ' on' : ''), type: 'button', text: inMy(it.id) ? 'در لیست من ✓' : '♡ افزودن به لیست من', onclick: function () { toggleMy(it.id); m.rebuild(); } }),
            h('button', { class: 'btn', type: 'button', text: 'اشتراک این مورد', onclick: function () { track('share', it.id); shareLink(pageUrl({ card: it.id }), it.name); } }),
            h('button', { class: 'btn', type: 'button', text: 'برنامه‌ی روز با این مورد', onclick: function () { m.close(); openPlan([it.id].concat(it.related.filter(function (r) { return r.id; }).slice(0, 2).map(function (r) { return r.id; }))); } }),
            CAN ? null : h('button', { class: 'btn', type: 'button', text: 'گزارش اشتباه', onclick: function () { openReport(it); } })), CAN && it.notes ? h('div', { class: 'rows' }, row('یادداشت من', it.notes)) : null, notices),
        CAN ? h('div', { class: 'mf' }, favBtn, doneBtn, aiBtn, h('span', { class: 'sp' }),
          h('button', { class: 'btn', type: 'button', text: 'ویرایش', onclick: function () { m.close(); openForm(getItem(curId), MANAGE ? { manage: true } : {}); } }),
          h('button', { class: 'btn btn-danger', type: 'button', text: 'حذف', onclick: function () {
            confirmBox('حذف تفریح', '«' + it.name + '» حذف شود؟ (از تنظیمات افزونه > تاریخچه تغییرات قابل برگشت است)', 'حذف', true).then(function (ok) {
              if (!ok) return;
              api('DELETE', 'items/' + it.id).then(function () {
                S.items = S.items.filter(function (x) { return x.id !== it.id; });
                m.close(); if (MANAGE) renderManage(); else { refreshOptions(); applyFilters(); } toast('حذف شد.');
              }).catch(function (e) { toast(e.message); });
            });
          } })) : null,
      ];
    });
  }

  function quick(it, patch, m) {
    api('POST', 'items/' + it.id, patch).then(function (r) { replaceItem(r.item); m.rebuild(); }).catch(function (e) { toast(e.message); });
  }

  /* ---------- فرم افزودن / ویرایش ---------- */
  function shrink(file) {
    return new Promise(function (res) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 400 * 1024) return res(file);
      var img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () {
        var s = Math.min(1, 1600 / Math.max(img.width, img.height)), c = document.createElement('canvas');
        c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(function (b) {
          URL.revokeObjectURL(url);
          res(b && b.size < file.size ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file);
        }, 'image/jpeg', 0.85);
      };
      img.onerror = function () { URL.revokeObjectURL(url); res(file); };
      img.src = url;
    });
  }

  function costText(list) { return (list || []).map(function (c) { return c.label + ' | ' + (c.price == null ? '' : (c.price === 0 ? 'رایگان' : c.price)) + (c.note ? ' | ' + c.note : ''); }).join('\n'); }
  function menuText(list) { return (list || []).map(function (x) { return (x.cat ? x.cat + ' | ' : '') + x.name + ' | ' + (x.price == null ? '' : (x.price === 0 ? 'رایگان' : x.price)); }).join('\n'); }
  function parseCosts(t) { return t.split('\n').map(function (l) { return l.trim(); }).filter(Boolean).map(function (l) { var p = l.split('|').map(function (x) { return x.trim(); }); return { label: p[0], price: p[1] === undefined ? '' : p[1], note: p[2] || '' }; }); }
  function parseMenu(t) { return t.split('\n').map(function (l) { return l.trim(); }).filter(Boolean).map(function (l) { var p = l.split('|').map(function (x) { return x.trim(); }); return p.length >= 3 ? { cat: p[0], name: p[1], price: p[2] } : { cat: '', name: p[0], price: p[1] || '' }; }); }

  function mapPicker(d, onPick) {
    var cur = d.lat != null ? [d.lat, d.lng] : null, map = null, mk = null, open = false;
    var txt = h('input', { class: 'inp', type: 'text', dir: 'ltr', placeholder: 'عرض و طول جغرافی، مثل 35.7000, 51.4000', value: cur ? cur[0] + ', ' + cur[1] : '' });
    var box = h('div', { class: 'pickmap', dir: 'ltr', hidden: true });
    var note = h('div', { class: 'hint', text: cur ? 'مختصات فعلی ثبت شده است.' : 'هنوز مختصاتی ثبت نشده.' });
    function ok(a, b) { return isFinite(a) && isFinite(b) && a >= 24 && a <= 40.5 && b >= 43.5 && b <= 64; }
    function set(a, b, fromMap) {
      a = Math.round(a * 1e7) / 1e7; b = Math.round(b * 1e7) / 1e7;
      cur = [a, b]; txt.value = a + ', ' + b; onPick(a, b); note.textContent = 'مختصات تازه انتخاب شد؛ با «ذخیره تغییرات» ثبت می‌شود.';
      if (map) { if (!mk) { mk = root.L.marker([a, b], { draggable: true }).addTo(map); mk.on('dragend', function () { var p = mk.getLatLng(); set(p.lat, p.lng, true); }); } else mk.setLatLng([a, b]); if (!fromMap) map.setView([a, b], Math.max(map.getZoom(), 16)); }
    }
    txt.addEventListener('change', function () {
      var m = txt.value.replace(/[۰-۹]/g, function (c) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(c); }).match(/(-?\d+(?:\.\d+)?)\s*[,،\s]\s*(-?\d+(?:\.\d+)?)/);
      if (m && ok(+m[1], +m[2])) set(+m[1], +m[2]); else { note.textContent = 'مختصات معتبر نیست (باید داخل ایران باشد).'; }
    });
    function init() {
      var L = root.L;
      map = L.map(box, { zoomControl: true }).setView(cur || [35.70, 51.40], cur ? 17 : 11);
      L.tileLayer(CFG.tiles || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
      if (cur) { mk = L.marker(cur, { draggable: true }).addTo(map); mk.on('dragend', function () { var p = mk.getLatLng(); set(p.lat, p.lng, true); }); }
      map.on('click', function (e) { set(e.latlng.lat, e.latlng.lng, true); });
      setTimeout(function () { map.invalidateSize(); }, 120);
    }
    var tog = h('button', { class: 'btn btn-sm', type: 'button', text: 'انتخاب دقیق روی نقشه', onclick: function () {
      open = !open; box.hidden = !open; tog.textContent = open ? 'بستن نقشه' : 'انتخاب دقیق روی نقشه';
      if (open) { if (map) setTimeout(function () { map.invalidateSize(); }, 80); else { box.textContent = 'در حال بارگذاری نقشه…'; loadMapLib().then(function () { box.textContent = ''; init(); }).catch(function (e) { box.textContent = 'نقشه بارگذاری نشد: ' + e.message; }); } }
    } });
    var me = h('button', { class: 'btn btn-sm', type: 'button', text: 'موقعیت فعلی من', onclick: function () {
      if (!navigator.geolocation) { note.textContent = 'مرورگر موقعیت‌یابی ندارد.'; return; }
      me.disabled = true; note.textContent = 'در حال گرفتن موقعیت…';
      navigator.geolocation.getCurrentPosition(function (p) { me.disabled = false; if (ok(p.coords.latitude, p.coords.longitude)) set(p.coords.latitude, p.coords.longitude); else note.textContent = 'موقعیت خارج از ایران است.'; },
        function () { me.disabled = false; note.textContent = 'دسترسی به موقعیت داده نشد.'; }, { enableHighAccuracy: true, timeout: 15000 });
    } });
    return h('div', { class: 'picker' }, txt, h('div', { class: 'links' }, tog, me), note, box,
      h('div', { class: 'hint', text: 'روی نقشه کلیک کنید یا نشانگر را بکشید تا دقیقا روی در ورودی مکان بنشیند. مختصات دقیق در تب «نقشه» همه نمایش داده می‌شود.' }));
  }

  function openForm(item, fopts) {
    fopts = fopts || {};
    var isNew = !item;
    var PUB = !CAN;
    var d = item || { name: '', category: '', row: 0, location: '', duration_h: 3, times: [], modes: MODE_KEYS.slice(), cost_note: '', costs: [], costs_extra: [], menu: [], related: [], extra: {}, city: '', area: '', address: '', hours: '', phone: '', website: '', description: '', notes: '', images: [], guess_modes: false };
    var times = d.times.slice(), modes = d.modes.slice(), tagsSel = (d.tags || []).slice(), modesTouched = false;
    var imgs = d.images.map(function (i) { return { id: i.id, thumb: i.thumb, ext: !!i.ext, url: i.url || '' }; }); // آیتم‌های ذخیره‌شده؛ ترتیب = ترتیب گالری
    var pending = []; // {file|url, preview}
    var busy = false;

    function inp(attrs) { return h('input', Object.assign({ class: 'inp', type: 'text' }, attrs)); }
    var F = {
      name: inp({ value: d.name, 'data-autofocus': '', placeholder: 'مثلا: کافه کتاب' }),
      category: inp({ value: d.category, list: 'tfy-cats', placeholder: 'مثلا: کافه' }),
      row: inp({ value: d.row || '', type: 'number', min: '1', placeholder: 'خودکار' }),
      location: inp({ value: d.lat != null ? '' : d.location, type: 'url', dir: 'ltr', placeholder: d.lat != null ? 'لوکیشن دقیق ثبت شده؛ برای تغییر، لینک نشان را بگذارید' : 'لینک نشان (لینک گوگل‌مپ به مختصات دقیق تبدیل می‌شود)' }),
      city: inp({ value: d.city, list: 'tfy-cities' }), area: inp({ value: d.area, list: 'tfy-areas' }),
      address: inp({ value: d.address }), hours: inp({ value: d.hours, placeholder: 'مثلا: هر روز ۹ تا ۲۲' }),
      phone: inp({ value: d.phone, dir: 'ltr' }), website: inp({ value: d.website, type: 'url', dir: 'ltr' }),
      cost_note: inp({ value: d.cost_note, placeholder: 'مثلا: ورودی حدود ۱۰۰ هزار تومان' }),
      costs: h('textarea', { class: 'inp', value: costText(d.costs), placeholder: 'هر خط: عنوان | قیمت (تومان یا رایگان) | توضیح\nمثال: ورودی | رایگان\nپارکینگ | 50000 | ساعتی' }),
      costs_extra: h('textarea', { class: 'inp', value: costText(d.costs_extra), placeholder: 'هزینه‌های اختیاری و پیشنهادی، با همان قالب\nمثال: تله‌کابین | 350000 | رفت و برگشت' }),
      menu: h('textarea', { class: 'inp', style: 'min-height:130px', value: menuText(d.menu), placeholder: 'هر خط: دسته | نام | قیمت\nمثال: نوشیدنی گرم | لاته | 180000' }),
      related: h('textarea', { class: 'inp', value: (d.related || []).map(function (r) { return r.name; }).join('\n'), placeholder: 'هر خط یک نام؛ اگر کارتی با همین نام در لیست باشد، کلیک‌پذیر می‌شود' }),
      description: h('textarea', { class: 'inp', value: d.description }), notes: h('textarea', { class: 'inp', value: d.notes, placeholder: 'فقط برای خودتان' }),
    };
    F.dur = h('select', { class: 'inp' }); DURS.forEach(function (x) { F.dur.appendChild(h('option', { value: x[0], text: x[1] })); }); F.dur.value = d.duration_h;
    var XF = {}; S.extra.forEach(function (fd) { XF[fd.key] = inp({ value: (d.extra && d.extra[fd.key]) || '' }); });
    var relPick = inp({ list: 'tfy-names', placeholder: 'انتخاب از کارت‌های موجود…' });
    var urlInp = inp({ dir: 'ltr', placeholder: 'https://… لینک مستقیم تصویر' });
    var fileInp = h('input', { type: 'file', accept: 'image/*', multiple: true, hidden: true });
    var errEl = h('div', { class: 'msg-err' }), aiNote = h('div');
    var hp = PUB ? h('input', { type: 'text', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true', style: 'position:absolute;left:-9999px;width:1px;height:1px;opacity:0' }) : null;
    var imgBox = h('div', { class: 'imgs' });

    function chipGroup(all, labelOf, arr, onTouch) {
      var box = h('div', { class: 'chips' });
      all.forEach(function (v) {
        var b = h('button', { class: 'chip' + (arr.indexOf(v) !== -1 ? ' on' : ''), type: 'button', text: labelOf(v), onclick: function () {
          var i = arr.indexOf(v); if (i === -1) arr.push(v); else arr.splice(i, 1);
          b.classList.toggle('on', i === -1); onTouch && onTouch();
        } });
        box.appendChild(b);
      });
      return box;
    }
    function fld(label, el, cls) { return h('div', { class: cls || '' }, h('label', { class: 'lbl', text: label }), el); }

    function drawImgs() {
      clear(imgBox);
      imgs.forEach(function (im, i) {
        imgBox.appendChild(h('div', { class: 'im' + (i === 0 ? ' cover' : '') }, pic(im.thumb, { alt: '' }),
          im.ext && !isNew ? h('button', { class: 'mc hostbtn', type: 'button', style: 'inset-inline-start:auto;inset-inline-end:2px;bottom:2px', text: 'بارگذاری در هاست', onclick: function (e) {
            e.target.disabled = true; e.target.textContent = '…';
            api('POST', 'items/' + item.id + '/image-host', { url: im.url }).then(function (r) {
              replaceItem(r.item); imgs.length = 0; r.item.images.forEach(function (x) { imgs.push({ id: x.id, thumb: x.thumb, ext: !!x.ext, url: x.url || '' }); }); drawImgs(); toast('در هاست بارگذاری شد.');
            }).catch(function (er) { toast(er.message); e.target.disabled = false; e.target.textContent = 'بارگذاری در هاست'; });
          } }) : null,
          h('button', { class: 'rm', type: 'button', text: 'حذف', onclick: function () { imgs.splice(i, 1); drawImgs(); } }),
          i === 0 ? h('button', { class: 'mc', type: 'button', disabled: true, text: 'کاور' }) : h('button', { class: 'mc', type: 'button', text: 'کاور شود', onclick: function () { imgs.unshift(imgs.splice(i, 1)[0]); drawImgs(); } })));
      });
      pending.forEach(function (p, i) {
        imgBox.appendChild(h('div', { class: 'im' }, h('img', { src: p.preview, alt: '' }),
          h('button', { class: 'rm', type: 'button', text: 'حذف', onclick: function () { pending.splice(i, 1); drawImgs(); } }),
          h('button', { class: 'mc', type: 'button', disabled: true, text: 'جدید' })));
      });
    }
    fileInp.addEventListener('change', function () {
      Array.prototype.forEach.call(fileInp.files, function (f) { pending.push({ file: f, preview: URL.createObjectURL(f) }); });
      fileInp.value = ''; drawImgs();
    });
    drawImgs();

    var pick = { lat: null, lng: null, dirty: false };
    var picker = (!PUB && CAN) ? mapPicker(d, function (a, b) { pick.lat = a; pick.lng = b; pick.dirty = true; F.location.value = ''; }) : null;
    function collect() {
      var o = collect0();
      if (pick.dirty) { o.lat = pick.lat; o.lng = pick.lng; o.location = ''; }
      return o;
    }
    function collect0() {
      return {
        name: F.name.value.trim(), category: F.category.value.trim(), row: parseInt(F.row.value, 10) || 0,
        location: F.location.value.trim(), duration_h: +F.dur.value, times: times, modes: modes, tags: tagsSel,
        cost_note: F.cost_note.value.trim(),
        city: F.city.value.trim(), area: F.area.value.trim(), address: F.address.value.trim(), hours: F.hours.value.trim(),
        phone: F.phone.value.trim(), website: F.website.value.trim(), description: F.description.value.trim(), notes: F.notes.value.trim(),
        costs: parseCosts(F.costs.value), costs_extra: parseCosts(F.costs_extra.value), menu: parseMenu(F.menu.value),
        related: F.related.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean),
        extra: (function () { var o = {}; Object.keys(XF).forEach(function (k) { o[k] = XF[k].value.trim(); }); return o; })(),
      };
    }
    function collectPub() {
      var p = collect(); delete p.row; delete p.notes;
      p.hp = hp ? hp.value : '';
      return p;
    }

    function aiFill(btn) {
      var p = collect();
      if (!p.name) { errEl.textContent = 'ابتدا نام تفریح را وارد کنید.'; return; }
      errEl.textContent = ''; btn.disabled = true; btn.textContent = 'در حال جستجو (تا ۱ دقیقه)…';
      api('POST', 'enrich', { name: p.name, category: p.category, description: p.description, location: p.location, city: p.city })
        .then(function (r) {
          var s = r.suggestion, n = 0;
          ['city', 'area', 'address', 'hours', 'phone', 'website', 'cost_note'].forEach(function (k) { if (!F[k].value && s[k]) { F[k].value = s[k]; n++; } });
          if (s.description_extra) { if (!F.description.value) { F.description.value = s.description_extra; n++; } else if (F.description.value.length < 120 && F.description.value.indexOf(s.description_extra) === -1) { F.description.value += '\n\n' + s.description_extra; n++; } }
          [['costs', 'costs'], ['costs_extra', 'costs_extra']].forEach(function (k) { if (!F[k[0]].value.trim() && s[k[1]] && s[k[1]].length) { F[k[0]].value = costText(s[k[1]]); n++; } });
          if (!F.menu.value.trim() && s.menu && s.menu.length) { F.menu.value = menuText(s.menu); n++; }
          if (s.modes && s.modes.length && (isNew || d.guess_modes) && !modesTouched) { modes.length = 0; s.modes.forEach(function (x) { modes.push(x); }); m.rebuild(); n++; }
          Object.keys(s.extra || {}).forEach(function (k) { if (XF[k] && !XF[k].value) { XF[k].value = s.extra[k]; n++; } });
          (s.image_urls || []).forEach(function (u) { if (!pending.some(function (x) { return x.url === u; })) { pending.push({ url: u, preview: u }); n++; } });
          drawImgs();
          clear(aiNote);
          aiNote.appendChild(h('div', { class: 'notice' }, n ? 'موارد خالی با نتیجه جستجو پر شد؛ قبل از ذخیره بررسی کنید.' : 'چیز تازه‌ای پیدا نشد.', s.sources.length ? [h('br'), 'منابع: ', s.sources.map(function (u, i) { return [i ? ' | ' : '', h('a', { href: safeUrl(u), target: '_blank', rel: 'noopener noreferrer', text: hostOf(u) })]; })] : null));
        })
        .catch(function (e) { errEl.textContent = e.message; })
        .then(function () { btn.disabled = false; btn.textContent = 'تکمیل خودکار با جستجو'; });
    }

    var m = openModal(function (m) {
      var saveBtn = h('button', { class: 'btn btn-primary', type: 'button', text: PUB ? 'ارسال پیشنهاد' : (isNew ? 'ثبت تفریح' : 'ذخیره تغییرات'), onclick: function () { save(saveBtn, m); } });
      var aiBtn = CAN && S.ai ? h('button', { class: 'btn', type: 'button', text: 'تکمیل خودکار با جستجو', onclick: function () { aiFill(aiBtn); } }) : null;
      return [head(PUB ? 'پیشنهاد تفریح جدید' : (isNew ? 'افزودن تفریح' : 'ویرایش تفریح'), m),
        h('div', { class: 'mb' }, PUB ? h('div', { class: 'notice', text: 'پیشنهاد شما پس از بررسی و تایید مدیریت در لیست نمایش داده می‌شود.' }) : null, hp, h('div', { class: 'fgrid' },
          fld('نام *', F.name, 'full'), fld('دسته', F.category), PUB ? null : fld('شماره ردیف', F.row),
          fld('مدت', F.dur),
          fld('زمان‌های مناسب', chipGroup(TIMES, function (t) { return t; }, times), 'full'),
          fld('مناسب برای', h('div', {}, chipGroup(MODE_KEYS, function (k) { return MODES[k]; }, modes, function () { modesTouched = true; }),
            d.guess_modes ? h('div', { class: 'hint' }, 'این حالت‌ها حدسی‌اند. با ذخیره‌ی فرم پس از اصلاح، «بازبینی‌شده» حساب می‌شوند. ', h('button', { class: 'linkbtn', type: 'button', text: 'تأیید همین‌ها', onclick: function () { modesTouched = true; toast('با ذخیره، تأیید می‌شود.'); } })) : null), 'full'),
          fld('ویژگی‌ها', chipGroup(TAG_KEYS, function (k) { return TAGS[k]; }, tagsSel), 'full'),
          fld('توضیح', F.description, 'full'), fld('توضیح هزینه', F.cost_note, 'full'),
          fld('هزینه‌های اصلی (ورودی، بلیت، پارکینگ…)', F.costs, 'full'), fld('هزینه‌های فرعی و پیشنهادی', F.costs_extra, 'full'),
          fld('منو و قیمت‌ها (رستوران و کافه)', F.menu, 'full'),
          fld('تفریحات تکمیلی', h('div', {}, F.related, h('div', { class: 'links' }, relPick, h('button', { class: 'btn btn-sm', type: 'button', text: 'افزودن', onclick: function () {
            var v = relPick.value.trim(); if (!v) return; F.related.value = (F.related.value.trim() ? F.related.value.trim() + '\n' : '') + v; relPick.value = '';
          } }))), 'full'),
          fld('شهر', F.city), fld('محدوده / محله', F.area), fld('آدرس', F.address, 'full'),
          fld('ساعت کاری', F.hours), fld('تلفن', F.phone), fld('وب‌سایت', F.website, 'full'), fld('لوکیشن دقیق روی نقشه', picker || h('div', { class: 'hint', text: d.lat != null ? 'مختصات ثبت شده است.' : 'ثبت نشده' }), 'full'), fld('یا لینک لوکیشن (نشان)', h('div', {}, F.location, d.lat != null ? h('div', { class: 'hint', text: 'مختصات: ' + d.lat + ' ، ' + d.lng + (d.loc_src === 'osm' ? ' (از OpenStreetMap با تطبیق نام؛ دقت را چک کنید)' : '') }) : null), 'full'),
          S.extra.map(function (fd) { return fld(fd.label, XF[fd.key]); }),
          PUB ? null : fld('یادداشت من', F.notes, 'full'),
          h('div', { class: 'full' }, h('label', { class: 'lbl', text: 'عکس‌ها (اولین عکس، کاور است)' }), imgBox,
            h('div', { class: 'links' }, h('button', { class: 'btn btn-sm', type: 'button', text: 'انتخاب عکس از گوشی/کامپیوتر', onclick: function () { fileInp.click(); } }), fileInp),
            PUB ? null : h('div', { class: 'links' }, urlInp, h('button', { class: 'btn btn-sm', type: 'button', text: 'افزودن از لینک (بدون آپلود در هاست)', onclick: function () {
              var u = urlInp.value.trim(); if (!safeUrl(u)) { errEl.textContent = 'لینک تصویر معتبر نیست.'; return; }
              errEl.textContent = ''; pending.push({ url: u, preview: u }); urlInp.value = ''; drawImgs();
            } })))),
          aiNote, errEl),
        h('div', { class: 'mf' }, saveBtn, aiBtn, h('span', { class: 'sp' }), h('button', { class: 'btn', type: 'button', text: 'انصراف', onclick: m.close }))];
    }, { sticky: true, inline: fopts.inline, onClose: function () { if (fopts.inline) openForm(null, fopts); } });

    function save(btn, m) {
      if (busy) return;
      var p = collect();
      if (!p.name) { errEl.textContent = 'نام تفریح الزامی است.'; F.name.focus(); return; }
      if (!times.length) { errEl.textContent = 'حداقل یک زمان مناسب انتخاب کنید (اگر مهم نیست: هروقت).'; return; }
      if (!modes.length) { errEl.textContent = 'حداقل یکی از گزینه‌های «مناسب برای» را انتخاب کنید.'; return; }
      errEl.textContent = ''; busy = true; btn.disabled = true; btn.textContent = 'در حال ذخیره…';
      if (!isNew) { p.gallery = imgs.map(function (i) { return i.ext ? i.url : i.id; }); if (modesTouched) p.guess_modes = false; }
      if (isNew && p.row === 0) delete p.row;
      if (PUB) p = collectPub();
      var req = isNew ? api('POST', 'items', p) : api('POST', 'items/' + item.id, p);
      req.then(function (r) {
        var id = PUB ? r.id : r.item.id, last = r.item || null, chain = Promise.resolve(), fails = 0;
        if (PUB && !id) return { item: null, fails: 0 };
        pending.forEach(function (x, i) {
          chain = chain.then(function () {
            btn.textContent = (x.file ? 'آپلود عکس ' : 'ثبت لینک عکس ') + fa(i + 1) + ' از ' + fa(pending.length) + '…';
            if (x.file) return shrink(x.file).then(function (f) { var fd = new FormData(); fd.append('file', f, f.name); return api('POST', 'items/' + id + '/media' + (PUB ? '?t=' + encodeURIComponent(r.token || '') : ''), undefined, fd); });
            return api('POST', 'items/' + id + '/image-url', { url: x.url });
          }).then(function (rr) { if (rr && rr.item) last = rr.item; }).catch(function () { fails++; });
        });
        return chain.then(function () { return { item: last, fails: fails }; });
      }).then(function (res) {
        if (PUB) { m.close(); toast(res.fails ? 'پیشنهاد ثبت شد (' + fa(res.fails) + ' عکس آپلود نشد). پس از تایید مدیریت نمایش داده می‌شود.' : 'پیشنهاد ثبت شد و پس از تایید مدیریت نمایش داده می‌شود.'); return; }
        replaceItem(res.item);
        if (MANAGE) renderManage(); else { refreshOptions(); if (isNew) applyFilters(); }
        m.close(); toast(res.fails ? 'ذخیره شد، ولی ' + fa(res.fails) + ' عکس ثبت نشد (لینک نامعتبر یا از شبکه‌ی اجتماعی).' : 'ذخیره شد.');
        if (!fopts.inline && !MANAGE) openDetail(res.item.id);
      }).catch(function (e) { errEl.textContent = e.message; busy = false; btn.disabled = false; btn.textContent = PUB ? 'ارسال پیشنهاد' : (isNew ? 'ثبت تفریح' : 'ذخیره تغییرات'); });
    }
  }


  /* ---------- مدیریت کارت‌ها (فقط تنظیمات افزونه) ---------- */
  var MQ = '';
  function buildManage() { refreshOptions0(); renderManage(); }
  function statusOf(it) { return it.status === 'pending' ? 'در انتظار تایید' : 'منتشرشده'; }
  function renderManage() {
    clear(rootEl);
    var pend = S.items.filter(function (x) { return x.status === 'pending'; });
    var q = norm(MQ);
    var rowsAll = S.items.filter(function (x) { return x.status !== 'pending'; }).filter(function (x) { return !q || norm(x.name + ' ' + x.category + ' ' + x.city).indexOf(q) !== -1; })
      .sort(function (a, b) { return (a.row || 0) - (b.row || 0); });
    function actBtn(label, cls, fn) { return h('button', { class: 'btn btn-sm ' + (cls || ''), type: 'button', text: label, onclick: fn }); }
    function del(it) {
      confirmBox('حذف تفریح', '«' + it.name + '» حذف شود؟ (از تاریخچه تغییرات قابل برگشت است)', 'حذف', true).then(function (ok) {
        if (!ok) return;
        api('DELETE', 'items/' + it.id).then(function () { S.items = S.items.filter(function (x) { return x.id !== it.id; }); renderManage(); toast('حذف شد.'); }).catch(function (e) { toast(e.message); });
      });
    }
    function decide(it, action) {
      api('POST', 'items/' + it.id + '/status', { action: action }).then(function (r) {
        if (action === 'reject') S.items = S.items.filter(function (x) { return x.id !== it.id; }); else replaceItem(r.item);
        renderManage(); toast(action === 'reject' ? 'پیشنهاد رد و حذف شد.' : 'تایید شد و در صفحه عمومی نمایش داده می‌شود.');
      }).catch(function (e) { toast(e.message); });
    }
    function rowEl(it, isPend) {
      var img = it.images && it.images.length ? h('img', { src: it.images[0].thumb, alt: '', style: 'width:44px;height:44px;object-fit:cover;border-radius:8px' }) : h('span');
      return h('tr', {}, h('td', {}, img), h('td', { text: it.name }), h('td', { text: it.category }), h('td', { text: it.city }),
        h('td', { text: isPend ? statusOf(it) : (it.row ? fa(it.row) : '') }),
        h('td', { class: 'macts' },
          isPend ? actBtn('تایید و انتشار', 'btn-primary', function () { decide(it, 'approve'); }) : null,
          actBtn('نمایش', '', function () { openDetail(it.id, {}); }),
          actBtn('ویرایش', '', function () { openForm(it, { manage: true }); }),
          isPend ? actBtn('رد', 'btn-danger', function () { decide(it, 'reject'); }) : actBtn('حذف', 'btn-danger', function () { del(it); })));
    }
    function table(list, isPend) {
      return h('div', { style: 'overflow-x:auto' }, h('table', { class: 'widefat striped', style: 'width:100%' },
        h('thead', {}, h('tr', {}, h('th', { text: '' }), h('th', { text: 'نام' }), h('th', { text: 'دسته' }), h('th', { text: 'شهر' }), h('th', { text: isPend ? 'وضعیت' : 'ردیف' }), h('th', { text: 'عملیات' }))),
        h('tbody', {}, list.map(function (it) { return rowEl(it, isPend); }))));
    }
    var qi = h('input', { class: 'inp', type: 'search', placeholder: 'جستجو در کارت‌ها…', value: MQ, 'aria-label': 'جستجو' });
    var t; qi.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { MQ = qi.value; renderManage(); var n = rootEl.querySelector('input[type=search]'); if (n) { n.focus(); n.setSelectionRange(MQ.length, MQ.length); } }, 250); });
    add(rootEl, [
      h('h2', { text: 'پیشنهادهای در انتظار تایید (' + fa(pend.length) + ')' }),
      pend.length ? table(pend, true) : h('p', { text: 'پیشنهاد جدیدی وجود ندارد.' }),
      h('h2', { text: 'کارت‌های منتشرشده (' + fa(rowsAll.length) + ')' }),
      qi, h('div', { style: 'height:8px' }), rowsAll.length ? table(rowsAll, false) : h('p', { text: 'موردی پیدا نشد.' }),
    ]);
  }

  /* ---------- شروع ---------- */
  start();
})(typeof window !== 'undefined' ? window : this);
