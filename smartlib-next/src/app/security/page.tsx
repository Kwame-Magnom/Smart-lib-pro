import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";
import MfaFlow from "./mfa-flow";

export default async function SecurityPage() {
  const supabase = await createClient();
  const { data: userData, error: userError } = await supabase.auth.getUser();
  if (userError || !userData.user) redirect("/login");

  const { data: assurance, error: assuranceError } =
    await supabase.auth.mfa.getAuthenticatorAssuranceLevel();
  if (assuranceError) {
    throw new Error(`Could not check your verification status: ${assuranceError.message}`);
  }
  if (assurance.currentLevel === "aal2") redirect("/library");

  return <MfaFlow email={userData.user.email ?? ""} />;
}
