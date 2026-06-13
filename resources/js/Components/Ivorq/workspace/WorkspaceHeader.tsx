import React from 'react';

interface WorkspaceHeaderProps {
  title: string;
  children?: React.ReactNode;
}

export default function WorkspaceHeader({ title, children }: WorkspaceHeaderProps) {
  return (
    <div className="workspace-header">
      <div className="workspace-title">{title}</div>
      {children && <div className="workspace-actions">{children}</div>}
    </div>
  );
}
