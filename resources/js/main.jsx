import React, { useEffect, useMemo, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { Area, AreaChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import './style.css'

const PERMISSIONS = [
  ['can_add_users', 'افزودن کاربر'],
  ['can_add_products', 'افزودن کالا'],
  ['can_edit_products', 'ویرایش کالاها'],
  ['can_change_balance', 'تغییر تراز'],
  ['can_edit_history', 'ویرایش تاریخچه تراز'],
  ['can_delete_history', 'حذف تاریخچه تراز'],
  ['can_edit_persons', 'افزودن و ویرایش اشخاص'],
  ['can_delete_persons', 'حذف اشخاص'],
  ['can_manage_permissions', 'مدیریت دسترسی‌ها'],
]

const UNITS = ['عدد', 'گرم', 'مثقال', 'انس']
const DIRECTIONS = ['خرید', 'فروش']
const SETTLEMENT_METHODS = ['حواله', 'کاغذ', 'ریال']

const csrfMeta = () => document.querySelector('meta[name=csrf-token]')

const request = (url, opt) =>
  fetch(url, {
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfMeta()?.content,
      Accept: 'application/json',
    },
    credentials: 'same-origin',
    ...opt,
    body: opt.body && JSON.stringify(opt.body),
  })

const parse = async (r) => {
  const data = await r.json().catch(() => null)
  if (r.status === 401) window.dispatchEvent(new Event('auth-expired'))
  if (!r.ok) throw Error(data?.message || Object.values(data?.errors || {}).flat()?.[0] || 'خطا')
  return data
}

const api = async (url, opt = {}) => {
  const first = await request(url, opt)
  if (first.status !== 419) return parse(first)

  // توکن صفحه کهنه شده؛ توکن تازه می‌گیریم و همان درخواست یک‌بار دیگر تکرار می‌شود
  const meta = csrfMeta()
  const fresh = await fetch('/api/token', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    .then((r) => r.json())
    .catch(() => null)
  if (meta && fresh?.token) {
    meta.setAttribute('content', fresh.token)
    return parse(await request(url, opt))
  }
  return parse(first)
}

const Msg = ({ x }) => x && <p className="msg">{x}</p>

// نمایش همه اعداد سایت با ارقام فارسی (ورودی فرم‌ها لاتین می‌ماند)
const fa = (x) => String(x ?? '').replace(/[0-9.]/g, (c) => (c === '.' ? '٫' : '۰۱۲۳۴۵۶۷۸۹'[c]))

const fmt = (x) => fa(+(+x).toFixed(3))

const EMPTY_PRODUCT = { name: '', sku: '', quantity: 0, unit: 'عدد' }
const EMPTY_CHANGE = { product_id: '', direction: 'خرید', quantity: '', unit_price: '', settlement_method: 'کاغذ', settlement_medium: 'ریال', settlement_date: '', person_id: '', from_person_id: '', to_person_id: '', note: '' }
const EMPTY_PERSON = { name: '', mobile: '', note: '' }
const EMPTY_USER = { name: '', mobile: '', can_add_users: false, can_add_products: false, can_edit_products: false, can_change_balance: false, can_edit_history: false, can_delete_history: false, can_edit_persons: false, can_delete_persons: false, can_manage_permissions: false }

function BalanceLineChart({ items, granularity = 'day' }) {
  const data = useMemo(() => [...items].reverse().map((item) => {
    const date = item.created_at ? new Date(item.created_at) : null
    let name = item.created_at_jalali?.slice(0, 10) || ''
    if (date && granularity === 'hour') name += ` ${String(date.getHours()).padStart(2, '0')}:00`
    if (date && granularity === 'minute') name += ` ${item.created_at_jalali?.slice(-5) || ''}`
    return { name, change: item.change_amount }
  }), [items, granularity])

  return (
    <div className="chart">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data} margin={{ top: 12, right: 24, bottom: 4, left: 4 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#e8edf4" />
          <XAxis dataKey="name" tick={{ fontSize: 11 }} tickFormatter={fa} reversed />
          <YAxis tick={{ fontSize: 11 }} width={48} orientation="right" tickFormatter={fa} />
          <Tooltip formatter={(value) => [fa(value), 'تغییر']} />
          <Line type="monotone" dataKey="change" name="تغییر" stroke="#0da38c" strokeWidth={2} dot={{ r: 3 }} />
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
}

function App() {
  const [user, setUser] = useState()
  const [page, setPage] = useState('داشبورد')
  const [msg, setMsg] = useState('')

  const load = () => api('/api/me').then(setUser).catch(() => setUser(null))
  useEffect(() => { load() }, [])

  if (!user) return <Login ok={load} />

  const can = (perm) => user.is_admin || user[perm]
  const nav = ['داشبورد',
    'فاکتورهای امروز',
    ...(can('can_add_products') || can('can_change_balance') ? ['کالاها'] : []),
    ...(can('can_edit_products') ? ['ویرایش کالاها'] : []),
    'تاریخچه',
    'اشخاص',
    ...(can('can_add_users') ? ['کاربران'] : []),
    'مشخصات']

  return (
    <main>
      <aside>
        <h1>● تراز</h1>
        {nav.map((x) => (
          <button key={x} className={page === x ? 'active' : ''} onClick={() => { setPage(x); setMsg('') }}>{x}</button>
        ))}
        <button onClick={() => api('/api/logout', { method: 'POST' }).then(load)}>خروج</button>
      </aside>
      <section>
        <header>
          <h2>{page}</h2>
          <span>{user.name} · {fa(user.mobile)}</span>
        </header>
        <Msg x={msg} />
        {page === 'داشبورد' && <Dashboard user={user} />}
        {page === 'فاکتورهای امروز' && <TodayInvoices />}
        {page === 'کالاها' && <Products user={user} ok={setMsg} />}
        {page === 'ویرایش کالاها' && <EditProducts user={user} ok={setMsg} />}
        {page === 'تاریخچه' && <History user={user} ok={setMsg} />}
        {page === 'اشخاص' && <Persons user={user} ok={setMsg} />}
        {page === 'کاربران' && <Users user={user} ok={setMsg} />}
        {page === 'مشخصات' && <Profile user={user} reload={load} ok={setMsg} />}
      </section>
    </main>
  )
}

function Login({ ok }) {
  const [mobile, setMobile] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const submit = (e) => {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    api('/api/login', { method: 'POST', body: { mobile, password } })
      .then(ok)
      .catch((q) => setError(q.message))
      .finally(() => setBusy(false))
  }
  return (
    <div className="login">
      <form onSubmit={submit}>
        <h1>تراز</h1>
        <input value={mobile} onChange={(e) => setMobile(e.target.value)} placeholder="موبایل" autoFocus />
        <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="رمز" />
        <button disabled={busy}>ورود</button>
        <Msg x={error} />
      </form>
    </div>
  )
}

function Dashboard({ user }) {
  const [data, setData] = useState({ products: [] })
  const [selected, setSelected] = useState(null)
  const [history, setHistory] = useState([])
  const [range, setRange] = useState({ from: '', to: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = () => api('/api/dashboard').then(setData)
  useEffect(() => { load() }, [])

  // اول دوره: ۱/۱ سال شمسی جاری
  const fiscalFrom = useMemo(() => {
    const year = (data.today_jalali || '').match(/(\d{4})/)
    return year ? `${year[1]}/01/01` : ''
  }, [data.today_jalali])

  const fetchHistory = (id, r) => {
    const query = new URLSearchParams()
    const from = r.from.trim() || fiscalFrom
    if (from) query.set('from', from)
    if (r.to.trim()) query.set('to', r.to.trim())
    api(`/api/products/${id}/history?${query.toString()}`).then(setHistory).catch(() => setHistory([]))
  }

  const choose = (item) => {
    setSelected({ ...item })
    const r = { from: '', to: '' }
    setRange(r)
    fetchHistory(item.id, r)
  }

  const applyRange = (e) => {
    e.preventDefault()
    if (selected) fetchHistory(selected.id, range)
  }
  const save = (e) => {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    api(`/api/products/${selected.id}`, { method: 'PUT', body: selected })
      .then(() => { setError('کالا و تراز اولیه ذخیره شد.'); load(); choose(selected) })
      .catch((z) => setError(z.message))
      .finally(() => setBusy(false))
  }

  return (
    <>
      <Msg x={error} />
      <div className="cards">
        {[['تعداد کالاها', data.products?.length], ['تغییرات امروز', data.changes_today], ['کاربران', data.users]]
          .map(([name, value]) => <article key={name}><small>{name}</small><b>{fa(value || 0)}</b></article>)}
      </div>
      <div className="panel">
        <div className="panel-title">
          <span>تراز کالاها</span>
          <small>{data.today_jalali ? `امروز: ${fa(data.today_jalali)}` : 'برای جزئیات روی کالا کلیک کنید'}</small>
        </div>
        <div className="product-balance">
          {(data.products || []).map((item) => (
            <article className="clickable" key={item.id} onClick={() => choose(item)}>
              <div><strong>{item.name}</strong><small>{item.sku || 'بدون کد'}</small></div>
              <b>{fmt(item.quantity)} <em>{item.unit || 'عدد'}</em></b>
            </article>
          ))}
        </div>
      </div>
      {selected && (
        <div className="panel">
          <h3>جزئیات {selected.name}</h3>
          {(user.is_admin || user.can_edit_products) && (
            <form className="form" onSubmit={save}>
              <input value={selected.name} onChange={(q) => setSelected({ ...selected, name: q.target.value })} />
              <input type="number" value={selected.quantity} step="any" onChange={(q) => setSelected({ ...selected, quantity: +q.target.value })} />
              <select value={selected.unit} onChange={(q) => setSelected({ ...selected, unit: q.target.value })}>
                {UNITS.map((u) => <option key={u}>{u}</option>)}
              </select>
              <button disabled={busy}>ذخیره ویرایش</button>
            </form>
          )}
          <form className="form range-form" onSubmit={applyRange}>
            <input placeholder="از تاریخ ۱۴۰۵/۰۱/۰۱" value={range.from} onChange={(q) => setRange({ ...range, from: q.target.value })} />
            <input placeholder="تا تاریخ ۱۴۰۵/۰۶/۳۱" value={range.to} onChange={(q) => setRange({ ...range, to: q.target.value })} />
            <button>نمایش بازه</button>
          </form>
          <small className="hint">
            تراز از اول دوره ({fa(fiscalFrom)}): {fmt(history.reduce((sum, item) => sum + (+item.change_amount || 0), 0))} {selected.unit || 'عدد'}
          </small>
          <ProductBalanceCharts history={history} unit={selected.unit} />
          <table>
            <thead><tr><th>تاریخ</th><th>شخص</th><th>کاربر</th><th>تغییر</th><th>تراز از اول دوره</th></tr></thead>
            <tbody>
              {[...history].reverse().map((item, index, all) => {
                let running = 0
                for (let i = 0; i <= index; i++) running += +all[i].change_amount || 0
                return (
                  <tr key={item.id}>
                    <td>{fa(item.created_at_jalali)}</td>
                    <td>{item.person?.name || '—'}</td>
                    <td>{item.user?.name}</td>
                    <td>{fmt(item.change_amount)}</td>
                    <td>{fmt(running)} {selected.unit}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

function Products({ user, ok }) {
  const [products, setProducts] = useState([])
  const [form, setForm] = useState(EMPTY_PRODUCT)
  const [change, setChange] = useState(EMPTY_CHANGE)
  const [persons, setPersons] = useState([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = () => api('/api/products').then(setProducts)
  const loadPersons = () => api('/api/persons').then(setPersons).catch(() => setPersons([]))
  useEffect(() => { load(); loadPersons() }, [])

  const canAdd = user.is_admin || user.can_add_products
  const canChange = user.is_admin || user.can_change_balance

  const post = (e, url, body, reset) => {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    api(url, { method: 'POST', body })
      .then(() => { reset(); load(); ok('ثبت شد.') })
      .catch((q) => setError(q.message))
      .finally(() => setBusy(false))
  }

  return (
    <>
      <Msg x={error} />
      {canAdd && (
        <div className="panel">
          <h3>افزودن کالا</h3>
          <form className="form" onSubmit={(x) => post(x, '/api/products', form, () => setForm(EMPTY_PRODUCT))}>
            <input required placeholder="نام کالا" value={form.name} onChange={(x) => setForm({ ...form, name: x.target.value })} />
            <input placeholder="کد کالا" value={form.sku} onChange={(x) => setForm({ ...form, sku: x.target.value })} />
            <input type="number" value={form.quantity} step="any" onChange={(x) => setForm({ ...form, quantity: +x.target.value })} />
            <select value={form.unit} onChange={(x) => setForm({ ...form, unit: x.target.value })}>
              {UNITS.map((u) => <option key={u}>{u}</option>)}
            </select>
            <button disabled={busy}>افزودن</button>
          </form>
        </div>
      )}
      {canChange && (
        <div className="panel">
          <h3>خرید و فروش (تغییر تراز کالا)</h3>
          <form className="form" onSubmit={(x) => post(x, `/api/products/${change.product_id}/balance`, change, () => setChange(EMPTY_CHANGE))}>
            <select required value={change.product_id} onChange={(x) => setChange({ ...change, product_id: x.target.value })}>
              <option value="">کالا را انتخاب کنید</option>
              {products.map((item) => <option key={item.id} value={item.id}>{item.name} ({fmt(item.quantity)} {item.unit || 'عدد'})</option>)}
            </select>
            <select value={change.direction} onChange={(x) => setChange({ ...change, direction: x.target.value })}>
              {DIRECTIONS.map((d) => <option key={d}>{d}</option>)}
            </select>
            <input required type="number" step="any" placeholder={`مقدار (${change.direction === 'خرید' ? 'ورود کالا' : 'خروج کالا'})`} value={change.quantity} onChange={(x) => setChange({ ...change, quantity: x.target.value })} />
            <input required type="number" step="any" placeholder="قیمت هر واحد/گرم" value={change.unit_price} onChange={(x) => setChange({ ...change, unit_price: x.target.value })} />
            <select value={change.settlement_method} onChange={(x) => setChange({ ...change, settlement_method: x.target.value })}>
              {SETTLEMENT_METHODS.map((m) => <option key={m}>{m}</option>)}
            </select>
            {change.settlement_method === 'حواله' && (
              <>
                <select value={change.settlement_medium} onChange={(x) => setChange({ ...change, settlement_medium: x.target.value })}>
                  <option value="ریال">حواله ریالی</option>
                  <option value="کاغذ">حواله کاغذی</option>
                </select>
                <select required value={change.from_person_id} onChange={(x) => setChange({ ...change, from_person_id: x.target.value })}>
                  <option value="">حواله از شخص</option>
                  {persons.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
                <select required value={change.to_person_id} onChange={(x) => setChange({ ...change, to_person_id: x.target.value })}>
                  <option value="">حواله به شخص</option>
                  {persons.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </>
            )}
            <select value={change.person_id} onChange={(x) => setChange({ ...change, person_id: x.target.value })}>
              <option value="">طرف معامله (اختیاری)</option>
              {persons.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
            <input placeholder="تاریخ تسویه شمسی ۱۴۰۵/۰۶/۰۱" value={change.settlement_date} onChange={(x) => setChange({ ...change, settlement_date: x.target.value })} />
            <input placeholder="یادداشت" value={change.note} onChange={(x) => setChange({ ...change, note: x.target.value })} />
            <button disabled={busy}>ثبت {change.direction}</button>
          </form>
          <small className="hint">
            مبلغ کل: {fmt((+change.quantity || 0) * (+change.unit_price || 0))} ·
            {' '}با تسویه {change.settlement_method === 'حواله' ? `حواله (${change.settlement_medium})` : change.settlement_method} مبلغ از محصول {change.settlement_method === 'کاغذ' ? 'کاغذ' : 'ریال'} کم/زیاد می‌شود؛ حواله بین دو شخص جابه‌جا می‌شود.
          </small>
        </div>
      )}
      <div className="panel">
        <h3>کالاها</h3>
        <table>
          <thead><tr><th>نام</th><th>کد</th><th>تراز</th><th>واحد</th></tr></thead>
          <tbody>
            {products.map((item) => (
              <tr key={item.id}><td>{item.name}</td><td>{item.sku || '—'}</td><td>{fmt(item.quantity)}</td><td>{item.unit || 'عدد'}</td></tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}

/**
 * Two line charts for the selected product: positive balance changes
 * (product added) on the right, negative changes (product removed) on the
 * left. Points are ordered and labelled by date, hour and minute.
 */
function ProductBalanceCharts({ history, unit }) {
  const positives = []
  const negatives = []

  ;[...history].reverse().forEach((item) => {
    if (!(item.change_amount > 0) && !(item.change_amount < 0)) return
    const point = { name: item.created_at_jalali, مقدار: Math.abs(item.change_amount) }
    ;(item.change_amount > 0 ? positives : negatives).push(point)
  })

  const tooltip = (unit) => ({ active, payload, label }) => {
    if (!active || !payload?.length) return null
    return (
      <div className="chart-tooltip">
        <b>{label}</b>
        <span>{fmt(payload[0].value)} {unit || 'عدد'}</span>
      </div>
    )
  }

  const chart = (data, title, color, id) => (
    <div className="half-chart">
      <div className="half-chart-title" style={{ '--dot': color }}>
        <span>{title}</span>
        <small>{fa(data.length)} مورد</small>
      </div>
      {data.length
        ? (
          <div className="chart small">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={data} margin={{ top: 14, right: 18, bottom: 4, left: 4 }}>
                <defs>
                  <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={color} stopOpacity={0.35} />
                    <stop offset="100%" stopColor={color} stopOpacity={0.02} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#e8edf4" vertical={false} />
                <XAxis dataKey="name" tick={{ fontSize: 10, fill: '#73809a' }} tickFormatter={fa} tickLine={false} axisLine={{ stroke: '#e8edf4' }} reversed />
                <YAxis tick={{ fontSize: 11, fill: '#73809a' }} tickFormatter={fa} tickLine={false} axisLine={false} width={52} orientation="right" />
                <Tooltip content={tooltip(unit)} />
                <Area type="monotone" dataKey="مقدار" stroke={color} strokeWidth={2.5} fill={`url(#${id})`} dot={{ r: 3.5, fill: color, strokeWidth: 0 }} activeDot={{ r: 5.5, strokeWidth: 2, stroke: '#fff' }} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        )
        : <div className="empty bordered">در این بازه داده‌ای ثبت نشده است.</div>}
    </div>
  )

  return (
    <div className="dual-charts">
      {chart(positives, `افزایش کالا (${unit || 'عدد'})`, '#0da38c', 'grad-up')}
      {chart(negatives, `کاهش کالا (${unit || 'عدد'})`, '#d64545', 'grad-down')}
    </div>
  )
}

function TodayInvoices() {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    api('/api/invoices/today').then(setData).catch((x) => setError(x.message))
  }, [])

  if (error) return <Msg x={error} />
  if (!data) return <div className="panel"><div className="empty">در حال بارگذاری…</div></div>

  const stats = data.stats || {}
  const cards = [
    ['تعداد فاکتورها', stats.count],
    ['مبلغ خریدها', fmt(stats.buy_value)],
    ['مبلغ فروش‌ها', fmt(stats.sale_value)],
    ['تراز کلی', fmt(stats.balance)],
    ['میانگین قیمت خرید', stats.avg_buy_price ? fmt(stats.avg_buy_price) : '—'],
    ['میانگین قیمت فروش', stats.avg_sale_price ? fmt(stats.avg_sale_price) : '—'],
    ['میانگین وزن خرید', stats.avg_buy_weight !== null && stats.avg_buy_weight !== undefined ? fmt(stats.avg_buy_weight) : '—'],
    ['میانگین وزن فروش', stats.avg_sale_weight !== null && stats.avg_sale_weight !== undefined ? fmt(stats.avg_sale_weight) : '—'],
  ]

  return (
    <>
      <div className="cards">
        {cards.map(([name, value]) => <article key={name}><small>{name}</small><b>{fa(value || 0)}</b></article>)}
      </div>
      <div className="panel">
        <div className="panel-title">
          <span>فاکتورهای امروز</span>
          <small>{fa(data.today_jalali)} · خرید: {fa(stats.buy_count || 0)} · فروش: {fa(stats.sale_count || 0)}</small>
        </div>
        {data.invoices?.length
          ? (
              <table>
                <thead>
                  <tr>
                    <th>ساعت</th><th>کالا</th><th>نوع</th><th>مقدار</th><th>قیمت واحد</th>
                    <th>مبلغ کل</th><th>تسویه</th><th>تاریخ تسویه</th><th>اشخاص</th>
                  </tr>
                </thead>
                <tbody>
                  {data.invoices.map((item) => (
                    <tr key={item.id}>
                      <td>{fa(item.created_at_jalali?.slice(-5) || '—')}</td>
                      <td>{item.product?.name || '—'}</td>
                      <td className={item.direction === 'خرید' ? 'up' : 'down'}>{item.direction || 'تعدیل'}</td>
                      <td>{fmt(item.change_amount)} {item.product?.unit || ''}</td>
                      <td>{item.unit_price ? fmt(item.unit_price) : '—'}</td>
                      <td>{item.total_price ? fmt(item.total_price) : '—'}</td>
                      <td>{item.settlement_method || '—'}</td>
                      <td>{fa(item.settlement_date_jalali || '—')}</td>
                      <td>
                        {item.from_person && item.to_person
                          ? `${item.from_person.name} → ${item.to_person.name}`
                          : item.person?.name || item.from_person?.name || item.to_person?.name || '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )
          : <div className="empty">امروز خرید و فروشی ثبت نشده است.</div>}
      </div>
    </>
  )
}

function EditProducts({ ok }) {
  const [products, setProducts] = useState([])
  const [editing, setEditing] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = () => api('/api/products').then(setProducts)
  useEffect(() => { load() }, [])

  const save = (e) => {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    api(`/api/products/${editing.id}`, { method: 'PUT', body: editing })
      .then(() => { setEditing(null); load(); ok('کالا ویرایش شد.') })
      .catch((z) => setError(z.message))
      .finally(() => setBusy(false))
  }

  return (
    <>
      <Msg x={error} />
      <div className="panel">
        <div className="panel-title">
          <span>ویرایش کالاها</span>
          <small>برای ویرایش، روی «ویرایش» کنار هر کالا بزنید</small>
        </div>
        <table>
          <thead><tr><th>نام</th><th>کد</th><th>تراز اولیه</th><th>واحد</th><th></th></tr></thead>
          <tbody>
            {products.map((item) => (
              editing?.id === item.id
                ? (
                    <tr key={item.id}>
                      <td colSpan="5">
                        <form className="form" onSubmit={save}>
                          <input required value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} />
                          <input placeholder="کد کالا" value={editing.sku || ''} onChange={(e) => setEditing({ ...editing, sku: e.target.value })} />
                          <input type="number" value={editing.quantity} step="any" onChange={(e) => setEditing({ ...editing, quantity: +e.target.value })} />
                          <select value={editing.unit} onChange={(e) => setEditing({ ...editing, unit: e.target.value })}>
                            {UNITS.map((u) => <option key={u}>{u}</option>)}
                          </select>
                          <button disabled={busy}>ذخیره</button>
                          <button type="button" className="ghost" onClick={() => setEditing(null)}>انصراف</button>
                        </form>
                      </td>
                    </tr>
                  )
                : (
                    <tr key={item.id}>
                      <td>{item.name}</td>
                      <td>{item.sku || '—'}</td>
                      <td>{fmt(item.quantity)}</td>
                      <td>{item.unit || 'عدد'}</td>
                      <td><button type="button" onClick={() => setEditing({ ...item })}>ویرایش</button></td>
                    </tr>
                  )
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}

function History({ user, ok }) {
  const [items, setItems] = useState([])
  const [options, setOptions] = useState({ users: [], products: [] })
  const [persons, setPersons] = useState([])
  const [filters, setFilters] = useState({ from: '', to: '', user_id: '', product_id: '' })
  const [editing, setEditing] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const canEdit = user.is_admin || user.can_edit_history
  const canDelete = user.is_admin || user.can_delete_history

  const load = () => {
    if (busy) return Promise.resolve()
    setBusy(true)
    const query = new URLSearchParams()
    if (filters.from) query.set('from', filters.from)
    if (filters.to) query.set('to', filters.to)
    if (filters.user_id) query.set('user_id', filters.user_id)
    if (filters.product_id) query.set('product_id', filters.product_id)
    return api(`/api/history?${query.toString()}`)
      .then((d) => { setItems(Array.isArray(d) ? d : []); setError('') })
      .catch((x) => { setItems([]); setError(x.message) })
      .finally(() => setBusy(false))
  }
  useEffect(() => {
    load()
    api('/api/history/options').then(setOptions).catch(() => {})
    if (canEdit) api('/api/persons').then(setPersons).catch(() => setPersons([]))
  }, [])

  const remove = (item) => {
    if (!window.confirm('این رکورد از تاریخچه حذف شود؟ تراز کالا و شخص به حالت قبل برمی‌گردد.')) return
    api(`/api/history/${item.id}`, { method: 'DELETE' })
      .then(() => { ok('رکورد حذف شد.'); load() })
      .catch((x) => setError(x.message))
  }

  const saveEdit = (e) => {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    api(`/api/history/${editing.id}`, { method: 'PUT', body: { amount: +editing.change_amount, note: editing.note, person_id: editing.person_id || null } })
      .then(() => { setEditing(null); ok('رکورد ویرایش شد.'); load() })
      .catch((x) => setError(x.message))
      .finally(() => setBusy(false))
  }

  return (
    <>
      <Msg x={error} />
      <div className="panel">
        <div className="panel-title"><span>فیلتر گزارش</span><small>تاریخ‌ها را به شمسی وارد کنید (نمونه: ۱۴۰۵/۰۶/۰۱)</small></div>
        <form className="form" onSubmit={(e) => { e.preventDefault(); load() }}>
          <select value={filters.user_id} onChange={(e) => setFilters({ ...filters, user_id: e.target.value })}>
            <option value="">همه کاربران</option>
            {options.users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
          <select value={filters.product_id} onChange={(e) => setFilters({ ...filters, product_id: e.target.value })}>
            <option value="">همه کالاها</option>
            {options.products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
          <input placeholder="از تاریخ ۱۴۰۵/۰۱/۰۱" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
          <input placeholder="تا تاریخ ۱۴۰۵/۰۶/۳۰" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
          <button disabled={busy}>اعمال فیلتر</button>
        </form>
      </div>
      {items.length
        ? <BalanceLineChart items={items} />
        : <div className="panel"><div className="empty">با این فیلترها تغییری یافت نشد.</div></div>}
      <div className="panel">
        <div className="panel-title"><span>ریز تغییرات</span><small>{fa(items.length)} مورد</small></div>
        <table>
          <thead><tr><th>تاریخ</th><th>کالا</th><th>شخص</th><th>کاربر</th><th>تغییر</th>{(canEdit || canDelete) && <th>عملیات</th>}</tr></thead>
          <tbody>
            {items.map((item) => (
              editing?.id === item.id
                ? (
                    <tr key={item.id}>
                      <td colSpan={canEdit || canDelete ? 6 : 5}>
                        <form className="form" onSubmit={saveEdit}>
                          <input required type="number" step="any" value={editing.change_amount} onChange={(e) => setEditing({ ...editing, change_amount: e.target.value })} />
                          <select value={editing.person_id || ''} onChange={(e) => setEditing({ ...editing, person_id: e.target.value })}>
                            <option value="">بدون شخص (تعدیل کلی)</option>
                            {persons.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                          </select>
                          <input placeholder="یادداشت" value={editing.note || ''} onChange={(e) => setEditing({ ...editing, note: e.target.value })} />
                          <button disabled={busy}>ذخیره</button>
                          <button type="button" className="ghost" onClick={() => setEditing(null)}>انصراف</button>
                        </form>
                      </td>
                    </tr>
                  )
                : (
                    <tr key={item.id}>
                      <td>{fa(item.created_at_jalali)}</td>
                      <td>{item.product?.name || '—'}</td>
                      <td>{item.person?.name || '—'}</td>
                      <td>{item.user?.name || '—'}</td>
                      <td className={item.change_amount > 0 ? 'up' : 'down'}>{item.change_amount > 0 ? '+' : ''}{fmt(item.change_amount)}</td>
                      {(canEdit || canDelete) && (
                        <td>
                          {canEdit && <button type="button" onClick={() => setEditing({ ...item, person_id: item.person?.id || '' })}>ویرایش</button>}
                          {canDelete && <button type="button" className="ghost" onClick={() => remove(item)}>حذف</button>}
                        </td>
                      )}
                    </tr>
                  )
            ))}
            {!items.length && <tr><td colSpan={canEdit || canDelete ? 6 : 5} className="empty-row">داده‌ای برای این فیلترها وجود ندارد.</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}

const statusClass = (status) => (status === 'بدهکار' ? 'debtor' : status === 'طلبکار' ? 'creditor' : status === 'تسویه' ? 'settled' : '')

// موبایل «0» یا خالی به‌عنوان بدون موبایل نمایش داده می‌شود
const displayMobile = (mobile) => (mobile && String(mobile).trim() !== '0' ? fa(mobile) : 'بدون موبایل')

function Persons({ user, ok }) {
  const [persons, setPersons] = useState([])
  const [form, setForm] = useState(EMPTY_PERSON)
  const [editing, setEditing] = useState(null)
  const [search, setSearch] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const canEdit = user.is_admin || user.can_edit_persons
  const canDelete = user.is_admin || user.can_delete_persons

  const load = (q = search) => {
    const query = q ? `?q=${encodeURIComponent(q)}` : ''
    return api(`/api/persons${query}`).then(setPersons).catch(() => setPersons([]))
  }
  useEffect(() => { load() }, [])

  const submitSearch = (e) => {
    e.preventDefault()
    load()
  }

  const submit = (e) => {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    api('/api/persons', { method: 'POST', body: form })
      .then(() => { setForm(EMPTY_PERSON); load(); ok('شخص اضافه شد.') })
      .catch((x) => setError(x.message))
      .finally(() => setBusy(false))
  }

  const saveEdit = (e) => {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    api(`/api/persons/${editing.id}`, { method: 'PUT', body: { name: editing.name, mobile: editing.mobile, note: editing.note } })
      .then(() => { setEditing(null); load(); ok('اطلاعات شخص ذخیره شد.') })
      .catch((x) => setError(x.message))
      .finally(() => setBusy(false))
  }

  const remove = (person) => {
    if (!window.confirm(`«${person.name}» حذف شود؟`)) return
    api(`/api/persons/${person.id}`, { method: 'DELETE' })
      .then(() => { load(); ok('شخص حذف شد.') })
      .catch((x) => setError(x.message))
  }

  const counts = persons.reduce((acc, p) => {
    if (p.status) acc[p.status] = (acc[p.status] || 0) + 1
    return acc
  }, {})
  const summary = [['بدهکار', counts['بدهکار'] || 0], ['طلبکار', counts['طلبکار'] || 0], ['تسویه', counts['تسویه'] || 0]]
    .map(([name, value]) => `${name}: ${fa(value)}`)
    .join(' · ')

  return (
    <>
      <Msg x={error} />
      {canEdit && (
        <div className="panel">
          <h3>افزودن شخص</h3>
          <form className="form" onSubmit={submit}>
            <input required placeholder="نام شخص" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            <input placeholder="موبایل (اختیاری)" value={form.mobile} onChange={(e) => setForm({ ...form, mobile: e.target.value })} />
            <input placeholder="یادداشت" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
            <button disabled={busy}>افزودن</button>
          </form>
        </div>
      )}
      <div className="panel">
        <div className="panel-title">
          <span>اشخاص و تراز آن‌ها</span>
          <small>{fa(persons.length)} شخص · {summary}</small>
        </div>
        <form className="form" onSubmit={submitSearch}>
          <input placeholder="جست‌وجو بر اساس نام یا شماره موبایل" value={search} onChange={(e) => setSearch(e.target.value)} />
          <button disabled={busy}>جست‌وجو</button>
        </form>
        {persons.length
          ? (
              <div className="product-balance">
                {persons.map((person) => (
                  <article key={person.id}>
                    <div>
                      {editing?.id === person.id
                        ? (
                            <form className="form" onSubmit={saveEdit}>
                              <input required placeholder="نام شخص" value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} />
                              <input placeholder="موبایل (اختیاری)" value={editing.mobile || ''} onChange={(e) => setEditing({ ...editing, mobile: e.target.value })} />
                              <input placeholder="یادداشت" value={editing.note || ''} onChange={(e) => setEditing({ ...editing, note: e.target.value })} />
                              <button disabled={busy}>ذخیره</button>
                              <button type="button" className="ghost" onClick={() => setEditing(null)}>انصراف</button>
                            </form>
                          )
                        : (
                            <>
                              <strong>{person.name}{person.status && <span className={`badge ${statusClass(person.status)}`}>{person.status}</span>}</strong>
                              <small>{displayMobile(person.mobile)}</small>
                              {person.note && <small>{person.note}</small>}
                              {(canEdit || canDelete) && (
                                <div className="chips">
                                  {canEdit && <button type="button" onClick={() => setEditing({ ...person })}>ویرایش</button>}
                                  {canDelete && <button type="button" className="ghost" onClick={() => remove(person)}>حذف</button>}
                                </div>
                              )}
                              {person.products?.length
                                ? (
                                    <div className="chips">
                                      {person.products.map((product) => (
                                        <span className={`chip ${statusClass(product.status)}`} key={product.id}>
                                          {product.name}:{' '}
                                          {product.quantity === 0
                                            ? 'تسویه'
                                            : `${fmt(Math.abs(product.quantity))} ${product.unit || ''} ${product.status}`}
                                        </span>
                                      ))}
                                    </div>
                                  )
                                : <div className="chips"><span className="chip settled">تسویه</span></div>}
                            </>
                          )}
                    </div>
                  </article>
                ))}
              </div>
            )
          : <div className="empty">شخصی ثبت نشده است.</div>}
      </div>
    </>
  )
}

function Users({ user, ok }) {
  const [users, setUsers] = useState([])
  const [form, setForm] = useState(EMPTY_USER)
  const [editing, setEditing] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = () => api('/api/users').then(setUsers)
  useEffect(() => { load() }, [])

  const canManage = user.is_admin || user.can_manage_permissions

  const toggle = (target, perm) => {
    const body = { ...target, [perm]: !target[perm] }
    api(`/api/users/${target.id}/permissions`, { method: 'PUT', body })
      .then(() => { setError(''); load() })
      .catch((x) => setError(x.message))
  }

  const submit = (e) => {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    api('/api/users', { method: 'POST', body: form })
      .then(() => { load(); setForm(EMPTY_USER); ok('کاربر با رمز ۱۲۳۴۵۶۷۸۹ ساخته شد.') })
      .catch((x) => setError(x.message))
      .finally(() => setBusy(false))
  }

  const saveEdit = (e) => {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    const body = { name: editing.name, mobile: editing.mobile }
    if (editing.password) body.password = editing.password
    api(`/api/users/${editing.id}`, { method: 'PUT', body })
      .then(() => { setEditing(null); load(); ok('اطلاعات کاربر ذخیره شد.') })
      .catch((x) => setError(x.message))
      .finally(() => setBusy(false))
  }

  return (
    <>
      <Msg x={error} />
      <div className="panel">
        <h3>افزودن کاربر</h3>
        <form className="form users" onSubmit={submit}>
          <input required placeholder="نام" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <input required placeholder="09xxxxxxxxx" value={form.mobile} onChange={(e) => setForm({ ...form, mobile: e.target.value })} />
          {PERMISSIONS.map(([key, name]) => (
            <label className="check" key={key}>
              <input type="checkbox" checked={form[key]} onChange={(e) => setForm({ ...form, [key]: e.target.checked })} />{name}
            </label>
          ))}
          <button disabled={busy}>ایجاد کاربر</button>
        </form>
      </div>
      <div className="panel">
        <div className="panel-title"><span>کاربران</span>{canManage && <small>تیک هر مجوز قابل تغییر است؛ با «ویرایش» اطلاعات کاربر را عوض کنید</small>}</div>
        <table>
          <thead><tr><th>نام</th><th>موبایل</th><th>{canManage ? 'دسترسی‌ها (قابل ویرایش)' : 'دسترسی‌ها'}</th><th></th></tr></thead>
          <tbody>
            {users.map((item) => (
              editing?.id === item.id
                ? (
                    <tr key={item.id}>
                      <td colSpan="4">
                        <form className="form" onSubmit={saveEdit}>
                          <input required value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} />
                          <input required placeholder="09xxxxxxxxx" value={editing.mobile} onChange={(e) => setEditing({ ...editing, mobile: e.target.value })} />
                          <input type="password" placeholder="رمز جدید (اختیاری)" onChange={(e) => setEditing({ ...editing, password: e.target.value })} />
                          <button disabled={busy}>ذخیره</button>
                          <button type="button" className="ghost" onClick={() => setEditing(null)}>انصراف</button>
                        </form>
                      </td>
                    </tr>
                  )
                : (
                    <tr key={item.id}>
                      <td>{item.name}{item.is_admin ? ' (مدیر کل)' : ''}</td>
                      <td>{fa(item.mobile)}</td>
                      <td>
                        {item.is_admin
                          ? 'همه دسترسی‌ها'
                          : (
                              <div className="chips">
                                {PERMISSIONS.map(([key, name]) => (
                                  <label className={`chip ${item[key] ? 'on' : ''}`} key={key}>
                                    <input type="checkbox" disabled={!canManage} checked={!!item[key]} onChange={() => toggle(item, key)} />{name}
                                  </label>
                                ))}
                              </div>
                            )}
                      </td>
                      <td>
                        {!item.is_admin && canManage && (
                          <button type="button" onClick={() => setEditing({ ...item, password: '' })}>ویرایش</button>
                        )}
                      </td>
                    </tr>
                  )
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}

function Profile({ user, reload, ok }) {
  const [form, setForm] = useState({ name: user.name, mobile: user.mobile, password: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = (e) => {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    api('/api/profile', { method: 'PUT', body: form })
      .then(() => { reload(); ok('ذخیره شد.') })
      .catch((x) => setError(x.message))
      .finally(() => setBusy(false))
  }

  return (
    <div className="panel profile">
      <h3>مشخصات و رمز عبور</h3>
      <Msg x={error} />
      <form onSubmit={submit}>
        <label>نام</label>
        <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <label>موبایل</label>
        <input value={form.mobile} onChange={(e) => setForm({ ...form, mobile: e.target.value })} />
        <label>رمز عبور جدید</label>
        <input type="password" placeholder="حداقل ۹ کاراکتر (اختیاری)" onChange={(e) => setForm({ ...form, password: e.target.value })} />
        <button disabled={busy}>ذخیره</button>
      </form>
    </div>
  )
}

class Boundary extends React.Component {
  constructor(props) {
    super(props)
    this.state = { bad: false, error: '' }
  }

  static getDerivedStateFromError() { return { bad: true } }

  componentDidCatch(error) { this.setState({ error: error.message }) }

  render() {
    return this.state.bad
      ? <div className="fatal">مشکلی در نمایش این بخش رخ داد: {this.state.error}</div>
      : this.props.children
  }
}

createRoot(document.getElementById('root')).render(<Boundary><App /></Boundary>)
