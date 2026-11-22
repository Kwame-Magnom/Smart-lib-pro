"use client";

import Link from "next/link";
import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase/browser";

type Step = "checking" | "setup" | "verify" | "reset";

export default function MfaFlow({ email }: { email: string }) {
  const router = useRouter();
  const [step, setStep] = useState<Step>("checking");
  const [factorId, setFactorId] = useState("");
  const [unverifiedFactorIds, setUnverifiedFactorIds] = useState<string[]>([]);
  const [qrCode, setQrCode] = useState("");
  const [secret, setSecret] = useState("");
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  useEffect(() => {
    let active = true;

    async function loadFactors() {
      try {
        const supabase = createClient();
        const { data, error: factorError } = await supabase.auth.mfa.listFactors();
        if (factorError) throw factorError;
        if (!active) return;

        const verifiedFactor = data.totp.find((factor) => factor.status === "verified");
        if (verifiedFactor) {
          setFactorId(verifiedFactor.id);
          setStep("verify");
          return;
        }

        const unverifiedIds = data.totp
          .filter((factor) => factor.status === "unverified")
          .map((factor) => factor.id);
        setUnverifiedFactorIds(unverifiedIds);
        setStep(unverifiedIds.length ? "reset" : "setup");
      } catch (caught) {
        if (active) {
          setError(caught instanceof Error ? caught.message : "Could not load authenticator setup.");
          setStep("setup");
        }
      }
    }

    void loadFactors();
    return () => {
      active = false;
    };
  }, []);

  async function beginSetup() {
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const supabase = createClient();
      for (const staleFactorId of unverifiedFactorIds) {
        const { error: removeError } = await supabase.auth.mfa.unenroll({
          factorId: staleFactorId,
        });
        if (removeError) throw removeError;
      }

      const { data, error: enrollError } = await supabase.auth.mfa.enroll({
        factorType: "totp",
        friendlyName: "SmartLib Pro authenticator",
        issuer: "SmartLib Pro",
      });
      if (enrollError) throw enrollError;

      setFactorId(data.id);
      setQrCode(data.totp.qr_code);
      setSecret(data.totp.secret);
      setUnverifiedFactorIds([]);
      setStep("verify");
      setMessage("Scan the QR code, then enter the six-digit code from your authenticator app.");
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Could not start authenticator setup.");
    } finally {
      setBusy(false);
    }
  }

  async function verifyCode(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const supabase = createClient();
      const { data: challenge, error: challengeError } =
        await supabase.auth.mfa.challenge({ factorId });
      if (challengeError) throw challengeError;

      const { error: verifyError } = await supabase.auth.mfa.verify({
        factorId,
        challengeId: challenge.id,
        code,
      });
      if (verifyError) throw verifyError;

      router.replace("/library");
      router.refresh();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "The authenticator code could not be verified.");
    } finally {
      setBusy(false);
    }
  }

  async function signOut() {
    setBusy(true);
    setError("");
    try {
      const supabase = createClient();
      const { error: signOutError } = await supabase.auth.signOut();
      if (signOutError) throw signOutError;
      router.replace("/login");
      router.refresh();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Could not sign out.");
      setBusy(false);
    }
  }

  return (
    <main className="security-page">
      <Link className="brand auth-brand" href="/">
        <span className="brand-icon" aria-hidden="true">S</span>
        <span>SmartLib <b>Pro</b></span>
      </Link>
      <section className="security-card">
        <p className="auth-step">TWO-FACTOR AUTHENTICATION</p>
        <h1>Secure your account</h1>
        <p className="security-intro">
          {email ? <>Signed in as <strong>{email}</strong>. </> : null}
          Complete verification with an authenticator app before opening your library.
        </p>

        {step === "checking" && <p role="status">Checking your authenticator setup…</p>}

        {(step === "setup" || step === "reset") && (
          <div className="mfa-setup">
            <p>
              Use an authenticator app such as 1Password, Google Authenticator, or Microsoft
              Authenticator. SmartLib shows the setup key only during enrollment; keep it
              private and do not share it.
            </p>
            {step === "reset" ? (
              <>
                <p className="form-message error-message">
                  An unfinished authenticator setup was found. Restarting removes only the
                  unverified setup and creates a new QR code.
                </p>
                <button className="button button-primary auth-submit" onClick={beginSetup} disabled={busy}>
                  {busy ? "Preparing setup…" : "Restart authenticator setup"}
                </button>
              </>
            ) : (
              <button className="button button-primary auth-submit" onClick={beginSetup} disabled={busy}>
                {busy ? "Preparing setup…" : "Set up authenticator"}
              </button>
            )}
          </div>
        )}

        {step === "verify" && (
          <div className="mfa-setup">
            {qrCode && (
              <div className="mfa-qr">
                <img src={qrCode} alt="QR code for SmartLib Pro authenticator setup" />
                <p>Scan this code in your authenticator app.</p>
                <p className="mfa-secret">Manual setup key: <code>{secret}</code></p>
              </div>
            )}
            <form className="auth-form" onSubmit={verifyCode}>
              <label htmlFor="totp-code">Six-digit authenticator code</label>
              <input
                id="totp-code"
                autoComplete="one-time-code"
                inputMode="numeric"
                pattern="[0-9]{6}"
                required
                maxLength={6}
                value={code}
                onChange={(event) => setCode(event.target.value.replace(/\D/g, "").slice(0, 6))}
              />
              <button className="button button-primary auth-submit" disabled={busy || code.length !== 6}>
                {busy ? "Verifying…" : qrCode ? "Verify and finish setup" : "Verify and continue"}
                <span aria-hidden="true">→</span>
              </button>
            </form>
          </div>
        )}

        {message && <p className="form-message success-message" role="status">{message}</p>}
        {error && <p className="form-message error-message" role="alert">{error}</p>}
        <button className="text-button" type="button" onClick={signOut} disabled={busy}>Sign out</button>
      </section>
      <p className="auth-legal">Your library data remains locked until the second factor is verified.</p>
    </main>
  );
}
