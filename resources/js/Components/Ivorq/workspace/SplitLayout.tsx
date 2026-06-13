import React from 'react';

export default function SplitLayout({ children }: { children: React.ReactNode }) {
  return <div className="split-layout">{children}</div>;
}
