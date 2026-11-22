"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase/browser";

export default function LoginForm() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [displayName, setDisplayName] = useState("");
  const [password, setPassword] = useState("");
  const [mode, setMode] = useState<"signin" | "signup">("signin");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submitCredentials(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setMessage("");
    setBusy(true);

    try {
      const supabase = createClient();
      if (mode === "signup") {
        const { data, error: signUpError } = await supabase.auth.signUp({
          email: email.trim(),
          password,
          options: { data: { display_name: displayName.trim() } },
        });
        if (signUpError) {
          setError(signUpError.message);
        } else if (data.session) {
          router.replace("/security");
          router.refresh();
        } else {
          setMode("signin");
          setMessage("Check your email to confirm your account, then sign in and set up your authenticator app.");
        }
      } else {
        const { error: signInError } = await supabase.auth.signInWithPassword({
          email: email.trim(),
          password,
        });
        if (signInError) {
          setError(signInError.message);
        } else {
          router.replace("/security");
          router.refresh();
        }
      }
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : "Could not complete sign-in.");
    } finally {
      setBusy(false);
    }
  }

  function changeMode(nextMode: "signin" | "signup") {
    setMode(nextMode);
    setError("");
    setMessage("");
  }

  return (
    <div>
      <div className="auth-mode-switch" role="group" aria-label="Account action">
        <button type="button" className={mode === "signin" ? "active" : ""} onClick={() => changeMode("signin")}>Sign in</button>
        <button type="button" className={mode === "signup" ? "active" : ""} onClick={() => changeMode("signup")}>Create account</button>
      </div>
      <form className="auth-form" onSubmit={submitCredentials}>
        {mode === "signup" && (
          <>
            <label htmlFor="display-name">Your name</label>
            <input
              id="display-name"
              autoComplete="name"
              maxLength={100}
              required
              placeholder="How should we address you?"
              value={displayName}
              onChange={(event) => setDisplayName(event.target.value)}
            />
          </>
        )}
        <label htmlFor="email">Email address</label>
        <input
          id="email"
          autoComplete="email"
          type="email"
          required
          maxLength={254}
          placeholder="you@school.edu"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
        />
        <label htmlFor="password">Password</label>
        <input
          id="password"
          autoComplete={mode === "signup" ? "new-password" : "current-password"}
          type="password"
          required
          minLength={12}
          maxLength={72}
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />
        {mode === "signup" && <p className="field-hint">Use at least 12 characters. You will set up an authenticator app after account confirmation.</p>}
        <button className="button button-primary auth-submit" disabled={busy}>
          {busy ? "Please wait…" : mode === "signup" ? "Create member account" : "Continue to verification"}
          <span aria-hidden="true">→</span>
        </button>
      </form>
      {message && <p className="form-message success-message" role="status">{message}</p>}
      {error && <p className="form-message error-message" role="alert">{error}</p>}
    </div>
  );
}
