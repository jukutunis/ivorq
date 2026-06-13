import React from 'react';
import Icon from '../primitives/Icon';

export default function GlobalSearch() {
  return (
    <div className="global-search-container">
      <Icon name="search" className="global-search-icon" />
      <input
        type="text"
        className="global-search-input"
        placeholder="Search guests, rooms, work orders, inventory..."
      />
      <div className="global-search-shortcut">⌘K</div>
    </div>
  );
}
