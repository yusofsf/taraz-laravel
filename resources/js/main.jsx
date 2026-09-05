import React, { useEffect, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import './style.css'

const PERMISSIONS = [
  ['can_add_users', 'افزودن کاربر'],
  ['can_add_products', 'افزودن کالا'],
  ['can_edit_products', 'ویرایش کالاها'],
  ['can_change_balance', 'تغییر تراز'],
  ['can_manage_permissions', 'مدیریت دسترسی‌ها'],
]

const UNITS = ['عدد', 'گرم', 'مثقال', 'انس']

const api = (url, opt = {}) =>
  fetch(url, {
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content,
      Accept: 'application/json',
    },
    credentials: 'same-origin',
    ...opt,
    body: opt.body && JSON.stringify(opt.body),
  }).then(async (r) => {
    const data = await r.json().catch(() => null)
    if (!r.ok) throw Error(data?.message || Object.values(data?.errors || {}).flat()?.[0] || 'خطا')
    return data
  })

const Msg = ({ x }) => x && <p className="msg">{x}</p>

const EMPTY_PRODUCT = { name: '', sku: '', quantity: 0, unit: 'عدد' }
const EMPTY_CHANGE = { product_id: '', amount: '', note: '', person_id: '' }
const EMPTY_PERSON = { name: '', mobile: '', note: '' }
const EMPTY_USER = { name: '', mobile: '', can_add_users: false, can_add_products: false, can_edit_products: false, can_change_balance: false, can_manage_permissions: false }

function BalanceLineChart({ items }) {
  const data = [...items].reverse().map((item, index) => ({
    name: item.created_at_jalali?.slice(0, 10) || String(index + 1),
    change: item.change_amount,
    balance: item.new_quantity,
  }))

  return (
    <div className="chart">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data} margin={{ top: 12, right: 24, bottom: 4, left: 4 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#e8edf4" />
          <XAxis dataKey="name" tick={{ fontSize: 11 }} reversed />
          <YAxis tick={{ fontSize: 11 }} width={48} orientation="right" />
          <Tooltip formatter={(value, key) => [value, key === 'change' ? 'تغییر' : 'تراز']} />
          <Line type="monotone" dataKey="change" name="تغییر" stroke="#0da38c" strokeWidth={2} dot={{ r: 3 }} />
          <Line type="monotone" dataKey="balance" name="تراز" stroke="#14233e" strokeWidth={2} strokeDasharray="6 3" dot={false} />
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
          <span>{user.name} · {user.mobile}</span>
        </header>
        <Msg x={msg} />
        {page === 'داشبورد' && <Dashboard user={user} />}
        {page === 'کالاها' && <Products user={user} ok={setMsg} />}
        {page === 'ویرایش کالاها' && <EditProducts user={user} ok={setMsg} />}
        {page === 'تاریخچه' && <History />}
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
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = () => api('/api/dashboard').then(setData)
  useEffect(() => { load() }, [])

  const choose = (item) => {
    setSelected({ ...item })
    api(`/api/products/${item.id}/history`).then(setHistory)
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
          .map(([name, value]) => <article key={name}><small>{name}</small><b>{value || 0}</b></article>)}
      </div>
      <div className="panel">
        <div className="panel-title">
          <span>تراز کالاها</span>
          <small>{data.today_jalali ? `امروز: ${data.today_jalali}` : 'برای جزئیات روی کالا کلیک کنید'}</small>
        </div>
        <div className="product-balance">
          {(data.products || []).map((item) => (
            <article className="clickable" key={item.id} onClick={() => choose(item)}>
              <div><strong>{item.name}</strong><small>{item.sku || 'بدون کد'}</small></div>
              <b>{item.quantity} <em>{item.unit || 'عدد'}</em></b>
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
              <input type="number" value={selected.quantity} onChange={(q) => setSelected({ ...selected, quantity: +q.target.value })} />
              <select value={selected.unit} onChange={(q) => setSelected({ ...selected, unit: q.target.value })}>
                {UNITS.map((u) => <option key={u}>{u}</option>)}
              </select>
              <button disabled={busy}>ذخیره ویرایش</button>
            </form>
          )}
          <BalanceLineChart items={history} />
          <table>
            <thead><tr><th>تاریخ</th><th>شخص</th><th>کاربر</th><th>تغییر</th><th>تراز جدید</th></tr></thead>
            <tbody>
              {history.map((item) => (
                <tr key={item.id}>
                  <td>{item.created_at_jalali}</td>
                  <td>{item.person?.name || '—'}</td>
                  <td>{item.user?.name}</td>
                  <td>{item.change_amount}</td>
                  <td>{item.new_quantity} {selected.unit}</td>
                </tr>
              ))}
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
            <input type="number" value={form.quantity} onChange={(x) => setForm({ ...form, quantity: +x.target.value })} />
            <select value={form.unit} onChange={(x) => setForm({ ...form, unit: x.target.value })}>
              {UNITS.map((u) => <option key={u}>{u}</option>)}
            </select>
            <button disabled={busy}>افزودن</button>
          </form>
        </div>
      )}
      {canChange && (
        <div className="panel">
          <h3>تغییر تراز کالا</h3>
          <form className="form" onSubmit={(x) => post(x, `/api/products/${change.product_id}/balance`, change, () => setChange(EMPTY_CHANGE))}>
            <select required value={change.product_id} onChange={(x) => setChange({ ...change, product_id: x.target.value })}>
              <option value="">کالا را انتخاب کنید</option>
              {products.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.quantity} {item.unit || 'عدد'})</option>)}
            </select>
            <select value={change.person_id} onChange={(x) => setChange({ ...change, person_id: x.target.value })}>
              <option value="">بدون شخص (تعدیل کلی)</option>
              {persons.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
            <input required type="number" placeholder="مثبت: دادن / منفی: گرفتن" value={change.amount} onChange={(x) => setChange({ ...change, amount: +x.target.value })} />
            <input placeholder="یادداشت" value={change.note} onChange={(x) => setChange({ ...change, note: x.target.value })} />
            <button disabled={busy}>ثبت تغییر</button>
          </form>
          <small className="hint">اگر شخصی انتخاب شود، تراز آن شخص برای همین کالا هم به همان میزان تغییر می‌کند.</small>
        </div>
      )}
      <div className="panel">
        <h3>کالاها</h3>
        <table>
          <thead><tr><th>نام</th><th>کد</th><th>تراز</th><th>واحد</th></tr></thead>
          <tbody>
            {products.map((item) => (
              <tr key={item.id}><td>{item.name}</td><td>{item.sku || '—'}</td><td>{item.quantity}</td><td>{item.unit || 'عدد'}</td></tr>
            ))}
          </tbody>
        </table>
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
                          <input type="number" value={editing.quantity} onChange={(e) => setEditing({ ...editing, quantity: +e.target.value })} />
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
                      <td>{item.quantity}</td>
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

function History() {
  const [items, setItems] = useState([])
  const [options, setOptions] = useState({ users: [], products: [] })
  const [filters, setFilters] = useState({ from: '', to: '', user_id: '', product_id: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

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
  }, [])

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
        <div className="panel-title"><span>ریز تغییرات</span><small>{items.length} مورد</small></div>
        <table>
          <thead><tr><th>تاریخ</th><th>کالا</th><th>شخص</th><th>کاربر</th><th>تغییر</th><th>تراز</th></tr></thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.id}>
                <td>{item.created_at_jalali}</td>
                <td>{item.product?.name || '—'}</td>
                <td>{item.person?.name || '—'}</td>
                <td>{item.user?.name || '—'}</td>
                <td className={item.change_amount > 0 ? 'up' : 'down'}>{item.change_amount > 0 ? '+' : ''}{item.change_amount}</td>
                <td>{item.new_quantity}</td>
              </tr>
            ))}
            {!items.length && <tr><td colSpan="6" className="empty-row">داده‌ای برای این فیلترها وجود ندارد.</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  )
}

function Persons({ user, ok }) {
  const [persons, setPersons] = useState([])
  const [form, setForm] = useState(EMPTY_PERSON)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = () => api('/api/persons').then(setPersons)
  useEffect(() => { load() }, [])

  const canAdd = user.is_admin || user.can_change_balance

  const submit = (e) => {
    e.preventDefault()
    if (busy) return
    setBusy(true)
    api('/api/persons', { method: 'POST', body: form })
      .then(() => { setForm(EMPTY_PERSON); load(); ok('شخص اضافه شد.') })
      .catch((x) => setError(x.message))
      .finally(() => setBusy(false))
  }

  return (
    <>
      <Msg x={error} />
      {canAdd && (
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
        <div className="panel-title"><span>اشخاص و تراز آن‌ها</span><small>{persons.length} شخص</small></div>
        {persons.length
          ? (
              <div className="product-balance">
                {persons.map((person) => (
                  <article key={person.id}>
                    <div>
                      <strong>{person.name}</strong>
                      <small>{person.mobile || 'بدون موبایل'}</small>
                      {person.products?.length
                        ? (
                            <div className="chips">
                              {person.products.map((product) => (
                                <span className="chip" key={product.id}>{product.name}: {product.pivot.quantity} {product.unit || ''}</span>
                              ))}
                            </div>
                          )
                        : <small>ترازی ثبت نشده</small>}
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
      .then(() => { load(); setForm(EMPTY_USER); ok('کاربر با رمز 123456789 ساخته شد.') })
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
                      <td>{item.name}{item.is_admin && ' (مدیر کل)'}</td>
                      <td>{item.mobile}</td>
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
