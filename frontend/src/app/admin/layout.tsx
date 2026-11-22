import type { ReactNode } from "react";
import "./crm65.css";

export const metadata = {
  title: "BOARDRIDERS · CRM",
  robots: { index: false, follow: false },
};

export default function AdminLayout({ children }: { children: ReactNode }) {
  return <div className="crm65-root" lang="ru">{children}</div>;
}
