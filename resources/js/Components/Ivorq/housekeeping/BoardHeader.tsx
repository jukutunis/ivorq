import React, { ReactNode } from 'react';

interface BoardHeaderProps {
  title: string;
  children?: ReactNode;
}

export default function BoardHeader({ title, children }: BoardHeaderProps) {
  return (
    <div className="board-header">
      <div className="board-title">{title}</div>
      {children && children}
    </div>
  );
}
