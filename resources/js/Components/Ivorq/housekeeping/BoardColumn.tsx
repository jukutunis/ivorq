import React, { ReactNode } from 'react';

interface BoardColumnProps {
  title: string;
  count?: number;
  children: ReactNode;
}

export default function BoardColumn({ title, count, children }: BoardColumnProps) {
  return (
    <div className="board-column">
      <div className="board-col-header">
        <div>{title}</div>
        {count !== undefined && <div className="col-count">{count}</div>}
      </div>
      <div className="board-col-body">
        {children}
      </div>
    </div>
  );
}
