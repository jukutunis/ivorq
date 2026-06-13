import React from 'react';
import AppTopbar from '../Components/Ivorq/shell/AppTopbar';

export default function IvorqLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <AppTopbar />
      {children}
    </>
  );
}
