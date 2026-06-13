import React, { ReactNode } from 'react';

interface WorkBoardProps {
  children: ReactNode;
}

export default function WorkBoard({ children }: WorkBoardProps) {
  return (
    <div className="work-board">
      {children}
    </div>
  );
}
