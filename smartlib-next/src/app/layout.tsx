import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "SmartLib Pro — Your school library, reimagined",
  description:
    "Discover books, manage your library activity, and stay connected with your school library.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
