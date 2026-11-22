import Link from "next/link";
import LoginForm from "./login-form";

export default function LoginPage() {
  return (
    <main className="auth-page">
      <Link className="brand auth-brand" href="/">
        <span className="brand-icon" aria-hidden="true">S</span>
        <span>SmartLib <b>Pro</b></span>
      </Link>
      <section className="auth-card">
        <div className="auth-aside">
          <div className="auth-art-mark" aria-hidden="true">✳</div>
          <p className="hero-kicker"><span /> YOUR NEXT CHAPTER STARTS HERE</p>
          <h1>Welcome back,<br /><em>book lover.</em></h1>
          <p>Sign in with your password, then confirm your identity with an authenticator-app code.</p>
          <div className="auth-quote"><span>“</span><p>A reader lives a thousand lives before he dies.</p><small>— George R. R. Martin</small></div>
        </div>
        <div className="auth-content">
          <span className="auth-step">MEMBER ACCESS</span>
          <h2>Sign in to your library</h2>
          <p className="auth-intro">Your account is protected by a password and a time-based authenticator code.</p>
          <LoginForm />
          <p className="auth-footnote">New registrations receive the standard member role. You will set up an authenticator app after creating your account. If you lose access to it, contact your library administrator for account recovery.</p>
          <Link className="back-home" href="/">← Back to home</Link>
        </div>
      </section>
      <p className="auth-legal">Secure sign-in powered by Supabase Auth</p>
    </main>
  );
}
