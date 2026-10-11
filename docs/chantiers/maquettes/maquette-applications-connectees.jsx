import React, { useState } from "react";

/*
 * Maquette — Applications connectées (#895)
 * Fait foi pour : hiérarchie des écrans, libellés français, états d'interaction.
 * Ne fait pas foi pour : classes CSS (Bootstrap 5 en production), iconographie.
 */

const ISSUER = "https://25sv.be";

const ROLES = [
  { id: "identified", label: "Membre identifié (parents compris)" },
  { id: "intendant", label: "Intendant" },
  { id: "chief", label: "Animateur (chef de section)" },
  { id: "admin", label: "Chef d'Unité" },
  { id: "superadmin", label: "Superadmin" },
];
const roleLabel = (id) => ROLES.find((r) => r.id === id)?.label ?? id;

const SCOPES = [
  { id: "openid", label: "Identifiant", detail: "un identifiant anonyme et stable de ton compte", locked: true },
  { id: "profile", label: "Nom", detail: "ton nom affiché (totem, sinon prénom), ton prénom et ton nom" },
  { id: "email", label: "Adresse e-mail", detail: "l'adresse avec laquelle tu te connectes" },
  { id: "scoutmagic", label: "Rôle et sections", detail: "ton rôle sur le site et tes sections de l'année (animateur ou animé)" },
];

const INITIAL_APPS = [
  {
    id: 1, name: "Wiki du staff", clientId: "sm_7f3a9c21e4", active: true,
    redirects: ["https://wiki.25sv.be/oauth/callback"], logoutRedirect: "https://wiki.25sv.be/",
    scopes: ["openid", "profile", "email", "scoutmagic"], role: "intendant",
    users: 14, lastLogin: "11/10/2026 18:42", createdAt: "02/10/2026",
  },
  {
    id: 2, name: "Nextcloud de l'unité", clientId: "sm_c02b81f7aa", active: true,
    redirects: ["https://cloud.25sv.be/apps/user_oidc/code"], logoutRedirect: "",
    scopes: ["openid", "profile", "email"], role: "chief",
    users: 9, lastLogin: "10/10/2026 21:05", createdAt: "05/10/2026",
  },
  {
    id: 3, name: "Boutique des uniformes", clientId: "sm_99de04b3c1", active: false,
    redirects: ["https://boutique.25sv.be/login/callback"], logoutRedirect: "",
    scopes: ["openid", "email"], role: "identified",
    users: 0, lastLogin: "—", createdAt: "09/10/2026",
  },
];

const MY_CONSENTS = [
  { appId: 1, grantedAt: "02/10/2026", lastLogin: "11/10/2026 18:42", scopes: ["openid", "profile", "email", "scoutmagic"] },
  { appId: 2, grantedAt: "05/10/2026", lastLogin: "10/10/2026 21:05", scopes: ["openid", "profile", "email"] },
];

/* ───────────── petits composants ───────────── */

function Btn({ children, variant = "primary", onClick, disabled, small, icon }) {
  const base = "inline-flex items-center gap-2 rounded border font-medium transition " +
    (small ? "px-3 py-1.5 text-sm min-h-[36px] " : "px-4 py-2 min-h-[44px] ");
  const styles = {
    primary: "bg-blue-600 border-blue-600 text-white hover:bg-blue-700",
    secondary: "bg-white border-gray-300 text-gray-800 hover:bg-gray-50",
    danger: "bg-white border-red-300 text-red-700 hover:bg-red-50",
    dangerSolid: "bg-red-600 border-red-600 text-white hover:bg-red-700",
    link: "border-transparent text-blue-700 hover:underline px-0",
  };
  return (
    <button type="button" onClick={onClick} disabled={disabled}
      className={base + styles[variant] + (disabled ? " opacity-40 cursor-not-allowed" : "")}>
      {icon && <span aria-hidden="true">{icon}</span>}
      {children}
    </button>
  );
}

function Card({ title, children, actions, tone }) {
  return (
    <section className={"rounded-lg border bg-white mb-4 " + (tone === "warn" ? "border-amber-300" : "border-gray-200")}>
      {title && (
        <header className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
          <h2 className="font-semibold text-gray-900">{title}</h2>
          {actions}
        </header>
      )}
      <div className="p-4">{children}</div>
    </section>
  );
}

function Pill({ children, tone = "gray" }) {
  const t = {
    green: "bg-green-100 text-green-800", gray: "bg-gray-100 text-gray-700",
    amber: "bg-amber-100 text-amber-800", blue: "bg-blue-100 text-blue-800",
  }[tone];
  return <span className={"inline-block rounded-full px-2 py-0.5 text-xs font-medium " + t}>{children}</span>;
}

function Switch({ checked, onChange, label, disabled }) {
  return (
    <label className={"flex items-center gap-3 min-h-[44px] " + (disabled ? "opacity-50" : "cursor-pointer")}>
      <span onClick={() => !disabled && onChange(!checked)} role="switch" aria-checked={checked}
        className={"relative w-11 h-6 rounded-full transition " + (checked ? "bg-blue-600" : "bg-gray-300")}>
        <span className={"absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition " + (checked ? "left-5" : "left-0.5")} />
      </span>
      <span>{label}</span>
    </label>
  );
}

function Modal({ title, children, onClose, footer }) {
  return (
    <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div className="flex items-center justify-between px-4 py-3 border-b">
          <h3 className="font-semibold">{title}</h3>
          <button type="button" onClick={onClose} className="w-11 h-11 text-gray-500" aria-label="Fermer">✕</button>
        </div>
        <div className="p-4">{children}</div>
        {footer && <div className="px-4 py-3 border-t flex justify-end gap-2 flex-wrap">{footer}</div>}
      </div>
    </div>
  );
}

function Breadcrumb({ items }) {
  return (
    <nav className="text-sm text-gray-500 mb-3" aria-label="Fil d'Ariane">
      {items.map((it, i) => (
        <span key={i}>
          {i > 0 && <span className="mx-1">›</span>}
          {it.onClick ? <button type="button" className="text-blue-700 hover:underline" onClick={it.onClick}>{it.label}</button>
            : <span className={i === items.length - 1 ? "text-gray-800" : ""}>{it.label}</span>}
        </span>
      ))}
    </nav>
  );
}

function Field({ label, help, children }) {
  return (
    <div className="mb-4">
      <label className="block text-sm font-medium text-gray-800 mb-1">{label}</label>
      {children}
      {help && <p className="text-xs text-gray-500 mt-1">{help}</p>}
    </div>
  );
}

function CopyLine({ value }) {
  const [done, setDone] = useState(false);
  return (
    <div className="flex items-stretch gap-2">
      <code className="flex-1 min-w-0 truncate rounded border bg-gray-50 px-3 py-2 text-sm">{value}</code>
      <Btn small variant="secondary" onClick={() => { setDone(true); setTimeout(() => setDone(false), 1200); }}>
        {done ? "Copié" : "Copier"}
      </Btn>
    </div>
  );
}

/* Écran de connexion "public" (hors coquille de configuration) */
function AuthShell({ children }) {
  return (
    <div className="min-h-[560px] bg-gray-100 flex items-start justify-center py-10 px-4">
      <div className="w-full max-w-md">
        <div className="text-center mb-6">
          <div className="inline-flex w-12 h-12 rounded-full bg-blue-600 text-white items-center justify-center font-bold">25</div>
          <div className="mt-2 font-semibold text-gray-800">Unité 25 SV</div>
        </div>
        <div className="bg-white rounded-lg border border-gray-200 shadow-sm p-6">{children}</div>
      </div>
    </div>
  );
}

/* ───────────── maquette ───────────── */

export default function MaquetteApplicationsConnectees() {
  const [screen, setScreen] = useState("list");
  const [mobile, setMobile] = useState(false);
  const [moduleOn, setModuleOn] = useState(true);
  const [apps, setApps] = useState(INITIAL_APPS);
  const [currentId, setCurrentId] = useState(1);
  const [draft, setDraft] = useState(null);
  const [secretModal, setSecretModal] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const [rotated, setRotated] = useState(false);
  const [flash, setFlash] = useState(null);
  const [consents, setConsents] = useState(MY_CONSENTS);
  const [consentExtended, setConsentExtended] = useState(false);
  const [mdOpen, setMdOpen] = useState(false);

  const current = apps.find((a) => a.id === currentId);

  const openApp = (id) => {
    const a = apps.find((x) => x.id === id);
    if (!a) { setScreen("list"); return; }
    setCurrentId(id);
    setDraft({ ...a, redirects: [...a.redirects], scopes: [...a.scopes] });
    setFlash(null);
    setScreen("detail");
  };
  const newApp = () => {
    setDraft({ id: null, name: "", clientId: "", active: true, redirects: [""], logoutRedirect: "",
      scopes: ["openid", "profile", "email"], role: "chief", users: 0, lastLogin: "—", createdAt: "11/10/2026" });
    setFlash(null);
    setScreen("detail");
  };
  const redirectError = (u) => {
    if (!u) return "Adresse obligatoire.";
    if (!u.startsWith("https://")) return "L'adresse doit commencer par https://";
    return null;
  };
  const saveApp = () => {
    if (!draft.name.trim() || draft.redirects.some(redirectError)) {
      setFlash({ tone: "error", text: "Corrige les champs signalés avant d'enregistrer." });
      return;
    }
    if (draft.id === null) {
      const id = apps.reduce((max, a) => Math.max(max, a.id), 0) + 1;
      const created = { ...draft, id, clientId: "sm_" + String(id).padStart(10, "0") };
      setApps([...apps, created]);
      setCurrentId(id);
      setDraft(created);
      setSecretModal({ name: created.name, clientId: created.clientId, secret: fakeSecret(), regenerated: false });
    } else {
      setApps(apps.map((a) => (a.id === draft.id ? draft : a)));
      setFlash({ tone: "ok", text: "Application enregistrée." });
    }
  };

  /* ---------- écrans ---------- */

  function ConfigNav() {
    return (
      <Breadcrumb items={[
        { label: "Configuration" },
        screen === "list" ? { label: "Applications connectées" } : { label: "Applications connectées", onClick: () => setScreen("list") },
        ...(screen === "detail" ? [{ label: draft?.id ? draft.name : "Nouvelle application" }] : []),
      ]} />
    );
  }

  function ListScreen() {
    return (
      <div>
        {ConfigNav()}
        <h1 className="text-2xl font-semibold mb-1">Applications connectées</h1>
        <p className="text-gray-600 mb-4">
          Ces sites utilisent les comptes de ce site pour connecter leurs utilisateurs. Chaque personne autorise une
          application à sa première connexion et peut retirer cet accès depuis son profil.
        </p>

        <Card title="Applications" actions={<Btn small icon="+" onClick={newApp}>Ajouter une application</Btn>}>
          {apps.length === 0 ? (
            <p className="text-gray-500">Aucune application. Ajoute le premier site qui doit utiliser les comptes de l'unité.</p>
          ) : (
            <div className="divide-y">
              {apps.map((a) => (
                <div key={a.id} className="py-3 flex items-center gap-3 flex-wrap">
                  <div className="flex-1 min-w-[200px]">
                    <div className="font-medium flex items-center gap-2">
                      {a.name} {a.active ? <Pill tone="green">Active</Pill> : <Pill>Désactivée</Pill>}
                    </div>
                    <div className="text-sm text-gray-500">
                      {roleLabel(a.role)} et plus · {a.users} {a.users > 1 ? "comptes autorisés" : "compte autorisé"} · dernière connexion {a.lastLogin}
                    </div>
                  </div>
                  <button type="button" onClick={() => openApp(a.id)} className="w-11 h-11 rounded border border-gray-300 hover:bg-gray-50"
                    aria-label={"Modifier — " + a.name} title={"Modifier — " + a.name}>✎</button>
                </div>
              ))}
            </div>
          )}
        </Card>

        <Card title="Pour les applications">
          <Field label="Adresse de découverte" help="La seule adresse à donner à un outil existant (WordPress, Nextcloud…) : il y trouve tout le reste.">
            <CopyLine value={ISSUER + "/.well-known/openid-configuration"} />
          </Field>
          <p className="text-sm text-gray-600">
            Le fichier d'instructions préremplies d'une application se télécharge depuis sa fiche.
          </p>
        </Card>

        <Card title="Clé de signature">
          <p className="text-sm text-gray-700 mb-3">
            Les applications vérifient avec cette clé que les informations reçues viennent bien de ce site.
          </p>
          <div className="text-sm mb-3 space-y-1">
            <div>Clé active : <code>{rotated ? "k-2026-10-11" : "k-2026-10-02"}</code> · créée le {rotated ? "11/10/2026" : "02/10/2026"}</div>
            {rotated && <div className="text-gray-500">Ancienne clé <code>k-2026-10-02</code> encore publiée jusqu'au 11/10/2026 à 21:12, le temps que les connexions en cours expirent.</div>}
          </div>
          <Btn small variant="secondary" onClick={() => setConfirm("rotate")}>Remplacer la clé</Btn>
        </Card>
      </div>
    );
  }

  function DetailScreen() {
    const d = draft;
    const set = (patch) => setDraft({ ...d, ...patch });
    const isNew = d.id === null;
    return (
      <div>
        {ConfigNav()}
        <h1 className="text-2xl font-semibold mb-4">{isNew ? "Nouvelle application" : d.name}</h1>

        {flash && (
          <div className={"rounded border px-4 py-3 mb-4 " + (flash.tone === "ok" ? "bg-green-50 border-green-200 text-green-800" : "bg-red-50 border-red-200 text-red-800")}>
            {flash.text}
          </div>
        )}

        <Card title="Application">
          <Field label="Nom affiché" help="Montré aux personnes lorsqu'elles autorisent l'application.">
            <input className={"w-full rounded border px-3 py-2 min-h-[44px] " + (!d.name.trim() && flash?.tone === "error" ? "border-red-400" : "border-gray-300")}
              value={d.name} onChange={(e) => set({ name: e.target.value })} placeholder="Ex. Wiki du staff" />
          </Field>

          <Field label="Adresses de retour" help="Copiées au caractère près depuis la configuration de l'application. Uniquement en https://.">
            {d.redirects.map((u, i) => {
              const err = flash?.tone === "error" ? redirectError(u) : null;
              return (
                <div key={i} className="mb-2">
                  <div className="flex gap-2">
                    <input className={"flex-1 rounded border px-3 py-2 min-h-[44px] font-mono text-sm " + (err ? "border-red-400" : "border-gray-300")}
                      value={u} placeholder="https://…/callback"
                      onChange={(e) => { const r = [...d.redirects]; r[i] = e.target.value; set({ redirects: r }); }} />
                    {d.redirects.length > 1 && (
                      <button type="button" className="w-11 h-11 rounded border border-gray-300" aria-label="Retirer cette adresse"
                        onClick={() => set({ redirects: d.redirects.filter((_, j) => j !== i) })}>🗑</button>
                    )}
                  </div>
                  {err && <p className="text-xs text-red-700 mt-1">{err}</p>}
                </div>
              );
            })}
            <Btn small variant="link" onClick={() => set({ redirects: [...d.redirects, ""] })}>+ Ajouter une adresse</Btn>
          </Field>

          <Field label="Adresse après déconnexion (facultative)" help="Où renvoyer la personne quand l'application la déconnecte aussi de ce site.">
            <input className="w-full rounded border border-gray-300 px-3 py-2 min-h-[44px] font-mono text-sm"
              value={d.logoutRedirect} onChange={(e) => set({ logoutRedirect: e.target.value })} placeholder="https://…" />
          </Field>

          <Field label="Qui peut se connecter" help="En dessous de ce rôle, la connexion est refusée avant même d'arriver sur l'application.">
            <select className="w-full rounded border border-gray-300 px-3 py-2 min-h-[44px]" value={d.role}
              onChange={(e) => set({ role: e.target.value })}>
              {ROLES.map((r) => <option key={r.id} value={r.id}>{r.label} et plus</option>)}
            </select>
          </Field>

          <Field label="Informations que l'application peut recevoir">
            <div className="space-y-1">
              {SCOPES.map((s) => (
                <label key={s.id} className={"flex items-start gap-3 min-h-[44px] py-1 " + (s.locked ? "opacity-70" : "cursor-pointer")}>
                  <input type="checkbox" className="mt-1 w-5 h-5" checked={d.scopes.includes(s.id)} disabled={s.locked}
                    onChange={(e) => set({ scopes: e.target.checked ? [...d.scopes, s.id] : d.scopes.filter((x) => x !== s.id) })} />
                  <span>
                    <span className="font-medium">{s.label}</span> <code className="text-xs text-gray-500">{s.id}</code>
                    <span className="block text-sm text-gray-600">{s.detail}{s.locked ? " — toujours transmis" : ""}</span>
                  </span>
                </label>
              ))}
            </div>
          </Field>

          {!isNew && <Switch checked={d.active} onChange={(v) => set({ active: v })}
            label={d.active ? "Application active" : "Application désactivée : plus aucune connexion n'est acceptée"} />}

          <div className="mt-4 flex justify-end">
            <Btn onClick={saveApp}>{isNew ? "Créer l'application" : "Enregistrer"}</Btn>
          </div>
        </Card>

        {!isNew && (
          <>
            <Card title="Identifiants">
              <Field label="Identifiant de l'application (client_id)">
                <CopyLine value={d.clientId} />
              </Field>
              <Field label="Secret" help="Il n'est affiché qu'une fois, au moment où il est créé. Si l'application l'a perdu, régénère-le : l'ancien cesse aussitôt de fonctionner.">
                <div className="flex items-center gap-3 flex-wrap">
                  <code className="rounded border bg-gray-50 px-3 py-2 text-sm text-gray-500">••••••••••••••••••••</code>
                  <Btn small variant="secondary" onClick={() => setConfirm("secret")}>Régénérer le secret</Btn>
                </div>
              </Field>
            </Card>

            <Card title="Instructions d'intégration">
              <p className="text-sm text-gray-700 mb-3">
                Un fichier prêt à donner au développeur de l'application, ou à l'IA qui l'aide. Il contient l'adresse de ce
                site, l'identifiant, les adresses de retour et les informations autorisées. <strong>Il ne contient pas le
                secret.</strong>
              </p>
              <div className="flex gap-2 flex-wrap">
                <Btn small icon="⬇">Télécharger les instructions d'intégration</Btn>
                <Btn small variant="secondary" onClick={() => setMdOpen(!mdOpen)}>{mdOpen ? "Masquer l'aperçu" : "Aperçu"}</Btn>
              </div>
              {mdOpen && <pre className="mt-3 max-h-80 overflow-auto rounded bg-gray-900 text-gray-100 text-xs p-3 whitespace-pre-wrap">{integrationMd(d)}</pre>}
            </Card>

            <Card title="Utilisation">
              <p className="text-sm text-gray-700">
                {d.users} {d.users > 1 ? "comptes ont" : "compte a"} autorisé cette application · dernière connexion {d.lastLogin} · créée le {d.createdAt}
              </p>
            </Card>

            <Card title="Supprimer l'application" tone="warn">
              <p className="text-sm text-gray-700 mb-3">Les personnes ne pourront plus s'y connecter avec leur compte, et toutes les autorisations données sont effacées.</p>
              <Btn small variant="danger" onClick={() => setConfirm("delete")}>Supprimer l'application</Btn>
            </Card>
          </>
        )}
      </div>
    );
  }

  function ConsentScreen() {
    const app = trackedApp();
    if (!app) return NoAppScreen();
    // In the "scopes étendus" variant, the role-and-sections scope is the one newly asked for.
    const already = consentExtended ? app.scopes.filter((s) => s !== "scoutmagic") : [];
    return (
      <AuthShell>
        <h1 className="text-xl font-semibold mb-1">{app.name}</h1>
        <p className="text-gray-700 mb-4">
          {consentExtended
            ? <>demande des informations supplémentaires sur ton compte <strong>Xavier</strong>.</>
            : <>souhaite utiliser ton compte <strong>Xavier</strong> pour te connecter.</>}
        </p>
        <p className="text-sm font-medium text-gray-800 mb-2">L'application recevra :</p>
        <ul className="mb-4 space-y-2">
          {SCOPES.filter((s) => app.scopes.includes(s.id)).map((s) => (
            <li key={s.id} className="flex gap-2 text-sm">
              <span aria-hidden="true" className="text-green-700">✓</span>
              <span>
                <span className="font-medium">{s.label}</span>
                {consentExtended && !already.includes(s.id) && <> <Pill tone="blue">Nouveau</Pill></>}
                <span className="block text-gray-600">{s.detail}</span>
              </span>
            </li>
          ))}
        </ul>
        <p className="text-xs text-gray-500 mb-4">
          Tu peux retirer cet accès à tout moment dans <em>Mon profil › Applications connectées</em>.
          L'application ne reçoit jamais ton mot de passe.
        </p>
        <div className="flex gap-2 justify-end flex-wrap">
          <Btn variant="secondary">Refuser</Btn>
          <Btn>Autoriser</Btn>
        </div>
      </AuthShell>
    );
  }

  function DeniedScreen() {
    const app = trackedApp();
    if (!app) return NoAppScreen();
    return (
      <AuthShell>
        <h1 className="text-xl font-semibold mb-2">Accès refusé</h1>
        <p className="text-gray-700 mb-4">
          Ton compte ne permet pas d'accéder à <strong>{app.name}</strong> : elle est réservée aux personnes
          ayant le rôle « {roleLabel(app.role)} » ou plus.
        </p>
        <p className="text-sm text-gray-600 mb-4">Si tu penses que c'est une erreur, contacte le staff d'unité.</p>
        <div className="flex justify-end">
          <Btn variant="secondary">Retourner sur {app.name}</Btn>
        </div>
      </AuthShell>
    );
  }

  function trackedApp() {
    return apps.find((a) => a.id === currentId) ?? apps[0] ?? null;
  }

  function NoAppScreen() {
    return (
      <AuthShell>
        <p className="text-gray-700">Aucune application n'est déclarée : cet écran n'a rien à montrer.</p>
      </AuthShell>
    );
  }

  function ProfileScreen() {
    return (
      <div>
        <Breadcrumb items={[{ label: "Mon profil" }, { label: "Applications connectées" }]} />
        <h1 className="text-2xl font-semibold mb-1">Applications connectées</h1>
        <p className="text-gray-600 mb-4">Les sites que tu as autorisés à utiliser ton compte pour te connecter.</p>
        {flash && <div className="rounded border px-4 py-3 mb-4 bg-green-50 border-green-200 text-green-800">{flash.text}</div>}
        <Card>
          {consents.length === 0 ? (
            <p className="text-gray-500">Tu n'as autorisé aucune application.</p>
          ) : (
            <div className="divide-y">
              {consents.map((c) => {
                const app = apps.find((a) => a.id === c.appId);
                if (!app) return null;
                return (
                  <div key={c.appId} className="py-3 flex items-start gap-3 flex-wrap">
                    <div className="flex-1 min-w-[200px]">
                      <div className="font-medium">{app.name}</div>
                      <div className="text-sm text-gray-600">
                        Reçoit : {SCOPES.filter((s) => c.scopes.includes(s.id)).map((s) => s.label.toLowerCase()).join(", ")}
                      </div>
                      <div className="text-xs text-gray-500">Autorisée le {c.grantedAt} · dernière connexion {c.lastLogin}</div>
                    </div>
                    <Btn small variant="danger" onClick={() => setConfirm({ revoke: c.appId })}>Retirer l'accès</Btn>
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      </div>
    );
  }

  function ModuleOffScreen() {
    return (
      <div>
        <Breadcrumb items={[{ label: "Configuration" }, { label: "Modules" }]} />
        <h1 className="text-2xl font-semibold mb-4">Modules</h1>
        <Card>
          <div className="flex items-start gap-3 flex-wrap">
            <div className="flex-1 min-w-[220px]">
              <div className="font-medium">Applications connectées</div>
              <p className="text-sm text-gray-600">Permet à d'autres sites de connecter leurs utilisateurs avec les comptes de ce site (OpenID Connect).</p>
              {!moduleOn && <p className="text-xs text-gray-500 mt-1">Désactivé : aucune application ne peut se connecter et l'adresse de découverte n'existe pas.</p>}
            </div>
            <Switch checked={moduleOn} onChange={setModuleOn} label={moduleOn ? "Activé" : "Désactivé"} />
          </div>
        </Card>
      </div>
    );
  }

  /* ---------- dialogues ---------- */

  function Dialogs() {
    return (
      <>
        {secretModal && (
          <Modal title={secretModal.regenerated ? "Nouveau secret" : "Application créée"} onClose={() => setSecretModal(null)}
            footer={<Btn onClick={() => setSecretModal(null)}>J'ai copié le secret</Btn>}>
            <p className="text-sm text-gray-700 mb-3">
              Copie ce secret maintenant et place-le dans la configuration de <strong>{secretModal.name}</strong> (de préférence
              une variable d'environnement). <strong>Il ne sera plus jamais affiché.</strong>
            </p>
            <Field label="Identifiant (client_id)"><CopyLine value={secretModal.clientId} /></Field>
            <Field label="Secret"><CopyLine value={secretModal.secret} /></Field>
          </Modal>
        )}
        {confirm === "secret" && (
          <Modal title="Régénérer le secret ?" onClose={() => setConfirm(null)}
            footer={<><Btn variant="secondary" onClick={() => setConfirm(null)}>Annuler</Btn>
              <Btn variant="dangerSolid" onClick={() => { setConfirm(null); setSecretModal({ name: draft.name, clientId: draft.clientId, secret: fakeSecret(), regenerated: true }); }}>Régénérer</Btn></>}>
            <p className="text-sm text-gray-700">L'ancien secret cesse de fonctionner immédiatement : <strong>{draft?.name}</strong> ne pourra plus connecter personne tant que le nouveau secret n'y est pas configuré.</p>
          </Modal>
        )}
        {confirm === "delete" && (
          <Modal title="Supprimer l'application ?" onClose={() => setConfirm(null)}
            footer={<><Btn variant="secondary" onClick={() => setConfirm(null)}>Annuler</Btn>
              <Btn variant="dangerSolid" onClick={() => {
                setApps(apps.filter((a) => a.id !== draft.id));
                setConsents(consents.filter((c) => c.appId !== draft.id));
                setCurrentId(null); setDraft(null); setConfirm(null); setScreen("list");
              }}>Supprimer</Btn></>}>
            <p className="text-sm text-gray-700"><strong>{draft?.name}</strong> sera supprimée, avec les {draft?.users} autorisations données. Cette action est définitive.</p>
          </Modal>
        )}
        {confirm === "rotate" && (
          <Modal title="Remplacer la clé de signature ?" onClose={() => setConfirm(null)}
            footer={<><Btn variant="secondary" onClick={() => setConfirm(null)}>Annuler</Btn>
              <Btn onClick={() => { setRotated(true); setConfirm(null); }}>Remplacer</Btn></>}>
            <p className="text-sm text-gray-700">Une nouvelle clé signe les prochaines connexions. L'ancienne reste publiée quelques minutes pour que les connexions en cours aboutissent. Les applications n'ont rien à changer.</p>
          </Modal>
        )}
        {confirm && confirm.revoke && (
          <Modal title="Retirer l'accès ?" onClose={() => setConfirm(null)}
            footer={<><Btn variant="secondary" onClick={() => setConfirm(null)}>Annuler</Btn>
              <Btn variant="dangerSolid" onClick={() => {
                const name = apps.find((a) => a.id === confirm.revoke).name;
                setConsents(consents.filter((c) => c.appId !== confirm.revoke)); setConfirm(null);
                setFlash({ tone: "ok", text: "Accès retiré à " + name + "." });
              }}>Retirer l'accès</Btn></>}>
            <p className="text-sm text-gray-700">
              {apps.find((a) => a.id === confirm.revoke).name} ne recevra plus d'informations sur ton compte. Pour t'y reconnecter, il faudra l'autoriser à nouveau.
            </p>
          </Modal>
        )}
      </>
    );
  }

  /* ---------- rendu ---------- */

  const SCREENS = [
    { id: "modules", label: "Activation du module" },
    { id: "list", label: "Config › Liste" },
    { id: "detail", label: "Config › Fiche" },
    { id: "consent", label: "Consentement" },
    { id: "denied", label: "Accès refusé" },
    { id: "profile", label: "Profil › Applications" },
  ];

  const go = (id) => {
    setFlash(null);
    if (id === "detail") {
      const target = trackedApp();
      if (target) openApp(target.id); else newApp();
      return;
    }
    setScreen(id);
  };

  let body;
  if (screen === "modules") body = ModuleOffScreen();
  else if (screen === "list") body = ListScreen();
  else if (screen === "detail" && draft) body = DetailScreen();
  else if (screen === "consent") body = ConsentScreen();
  else if (screen === "denied") body = DeniedScreen();
  else if (screen === "profile") body = ProfileScreen();

  const isAuth = screen === "consent" || screen === "denied";

  return (
    <div className="font-sans text-gray-900 bg-gray-50 min-h-screen">
      <div className="sticky top-0 z-40 bg-amber-50 border-b border-amber-200 px-4 py-2 flex flex-wrap items-center gap-2 text-sm">
        <span className="font-semibold text-amber-900 mr-2">Maquette #895</span>
        {SCREENS.map((s) => (
          <button key={s.id} type="button" onClick={() => go(s.id)}
            className={"rounded px-2 py-1 " + (screen === s.id ? "bg-amber-600 text-white" : "bg-white border border-amber-300 text-amber-900")}>
            {s.label}
          </button>
        ))}
        {screen === "consent" && (
          <label className="flex items-center gap-1 ml-2">
            <input type="checkbox" checked={consentExtended} onChange={(e) => setConsentExtended(e.target.checked)} />
            Scopes étendus
          </label>
        )}
        <label className="flex items-center gap-1 ml-auto">
          <input type="checkbox" checked={mobile} onChange={(e) => setMobile(e.target.checked)} /> Mobile
        </label>
      </div>

      <div className={mobile ? "mx-auto my-4 w-[390px] border-8 border-gray-800 rounded-[2rem] overflow-hidden bg-gray-50" : ""}>
        {isAuth ? body : (
          <div className={(mobile ? "px-4" : "max-w-4xl mx-auto px-6") + " py-6"}>{body}</div>
        )}
      </div>
      {Dialogs()}
    </div>
  );
}

// Fixed placeholder values: a mockup never generates anything secret.
const FAKE_SECRETS = ["EXAMPLE-SECRET-not-a-real-value-0001", "EXAMPLE-SECRET-not-a-real-value-0002"];
let fakeSecretIndex = 0;
function fakeSecret() {
  fakeSecretIndex = (fakeSecretIndex + 1) % FAKE_SECRETS.length;
  return FAKE_SECRETS[fakeSecretIndex];
}

function integrationMd(app) {
  return `# Integrating "${app.name}" with ScoutMagic (OpenID Connect)

> Give this whole file to the developer or AI building the integration.
> It is self-contained. The client secret is NOT in this file.

## 1. Configuration
| Setting | Value |
|---|---|
| Issuer | ${ISSUER} |
| Discovery URL | ${ISSUER}/.well-known/openid-configuration |
| client_id | ${app.clientId} |
| client_secret | read from env var SCOUTMAGIC_CLIENT_SECRET (never commit it) |
| Redirect URI(s) | ${app.redirects.join(", ")} |
| Post-logout redirect | ${app.logoutRedirect || "(none declared)"} |
| Scopes | ${app.scopes.join(" ")} |
| Minimum role | ${app.role} — users below it get error=access_denied |

## 2. Flow (Authorization Code + PKCE S256, confidential client)
1. Generate state, nonce, code_verifier; redirect to authorization_endpoint
   with response_type=code, code_challenge_method=S256.
2. On callback, check state, exchange code at token_endpoint
   (client_secret_basic). Codes are single-use and expire after 60 s.
3. Validate the ID token: RS256 via jwks_uri, iss, aud, exp, nonce.
4. Optionally call userinfo_endpoint with the access token (10 min).

## 3. Claims
sub (opaque, stable) · name · given_name · family_name · email ·
email_verified · scoutmagic_role · scoutmagic_sections[{code,name,function}]

## 4. Sessions
No refresh token. When your session expires, redirect again with
prompt=none; handle login_required / consent_required.
Logout: end_session_endpoint with id_token_hint + post_logout_redirect_uri.

## 5. Recommended libraries
- PHP: jumbojett/openid-connect-php
- Node: openid-client
- Python: Authlib
- WordPress: "OpenID Connect Generic" — paste the discovery URL
- Nextcloud: user_oidc — paste the discovery URL

## 6. Errors to handle
access_denied · login_required · consent_required · invalid_grant

## 7. Security rules
HTTPS only · secret outside the code · exact redirect URIs ·
never store tokens in the browser.

## 8. Checklist
- [ ] Login works for an allowed account
- [ ] An account below "${app.role}" is refused cleanly
- [ ] Refusing consent is handled
- [ ] ID token signature, iss, aud, exp and nonce are checked
- [ ] Silent re-login and logout work
`;
}
