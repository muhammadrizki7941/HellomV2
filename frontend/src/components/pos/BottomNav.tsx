import React, { useState, useEffect, useRef } from 'react';
import {
  LayoutDashboard,
  ShoppingBag,
  Package,
  Grid2x2,
  MoreHorizontal,
  Users,
  User,
  Settings,
  BarChart3,
  Ticket
} from 'lucide-react';
import { cn } from '@/lib/utils';

interface BottomNavProps {
  activeTab: string;
  onTabChange: (tab: string) => void;
  activeOrdersCount?: number;
}

const moreItems = [
  { id: 'staff', label: 'Staff', icon: Users },
  { id: 'members', label: 'Member', icon: User },
  { id: 'loyalty', label: 'Loyalitas', icon: Settings },
  { id: 'customer-hub', label: 'Promo', icon: Ticket },
  { id: 'reports', label: 'Laporan', icon: BarChart3 },
];

const OrdersBadge = ({ count }: { count?: number }) => {
  if (!count || count <= 0) return null;

  return (
    <span className="absolute -top-1 -right-1 bg-red-500 text-white text-[9px] font-bold min-w-[16px] h-4 rounded-full flex items-center justify-center px-1 leading-none">
      {count > 99 ? '99+' : count}
    </span>
  );
};

const MoreSheet = ({
  isOpen,
  onClose,
  onSelect
}: {
  isOpen: boolean;
  onClose: () => void;
  onSelect: (tab: string) => void;
}) => (
  <>
    {/* Overlay */}
    <div
      className={cn(
        'fixed inset-0 bg-black/40 z-40 transition-opacity duration-300',
        isOpen ? 'opacity-100' : 'opacity-0 pointer-events-none'
      )}
      onClick={onClose}
    />
    {/* Sheet */}
    <div
      className={cn(
        'fixed bottom-0 left-0 right-0 z-50 bg-white rounded-t-3xl transition-transform duration-300 pb-safe',
        isOpen ? 'translate-y-0' : 'translate-y-full'
      )}
    >
      {/* Handle */}
      <div className="flex justify-center pt-3 pb-4">
        <div className="w-10 h-1 bg-gray-200 rounded-full" />
      </div>
      <p className="text-center text-xs text-gray-400 mb-4 font-medium">
        More Options
      </p>
      {/* Grid 2x2 */}
      <div className="grid grid-cols-4 gap-4 px-6 pb-8">
        {moreItems.map(item => (
          <button
            key={item.id}
            onClick={() => {
              onSelect(item.id);
              onClose();
            }}
            className="flex flex-col items-center gap-2 active:scale-95 transition-transform"
          >
            <div className="w-14 h-14 bg-gray-50 rounded-2xl flex items-center justify-center hover:bg-gray-100 transition-colors">
              <item.icon className="w-6 h-6 text-gray-700" />
            </div>
            <span className="text-xs text-gray-600 font-medium">
              {item.label}
            </span>
          </button>
        ))}
      </div>
    </div>
  </>
);

const navItems = [
  { id: 'admin-dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { id: 'menu', label: 'Products', icon: Package },
  { id: 'orders', label: 'Orders', icon: ShoppingBag, isCenter: true, hasBadge: true },
  { id: 'tables', label: 'Meja', icon: Grid2x2 },
];

export default function BottomNav({ activeTab, onTabChange, activeOrdersCount }: BottomNavProps) {
  const [visible, setVisible] = useState(false);
  const [showMoreSheet, setShowMoreSheet] = useState(false);
  const hideTimerRef = useRef<NodeJS.Timeout | null>(null);

  const startHideTimer = () => {
    // Clear existing timer
    if (hideTimerRef.current) {
      clearTimeout(hideTimerRef.current);
    }
    
    // Set new timer untuk hide setelah 5 detik
    hideTimerRef.current = setTimeout(() => {
      setVisible(false);
    }, 5000);
  };

  const handleInteraction = () => {
    // Show nav saat ada interaksi
    setVisible(true);
    // Reset timer
    startHideTimer();
  };

  useEffect(() => {
    // Animasi masuk saat load
    setVisible(true);
    startHideTimer();

    // Event listener untuk show nav saat ada interaksi
    document.addEventListener('touchstart', handleInteraction);
    document.addEventListener('mousedown', handleInteraction);

    return () => {
      document.removeEventListener('touchstart', handleInteraction);
      document.removeEventListener('mousedown', handleInteraction);
      if (hideTimerRef.current) {
        clearTimeout(hideTimerRef.current);
      }
    };
  }, []);

  const handleTabChange = (tabId: string) => {
    handleInteraction();
    if (tabId === 'more') {
      setShowMoreSheet(true);
    } else {
      onTabChange(tabId);
    }
  };

  const handleMoreSelect = (tabId: string) => {
    onTabChange(tabId);
  };

  return (
    <>
      {/* Floating Pill Navigation */}
      <div
        className={cn(
          'fixed bottom-0 left-0 right-0 z-50 transition-transform duration-500 pb-safe hidden md:block',
          visible ? 'translate-y-0' : 'translate-y-full'
        )}
      >
        <div className="px-4 pb-3">
          <div
            className="mx-auto max-w-md bg-white rounded-3xl shadow-[0_-2px_20px_rgba(0,0,0,0.08),0_8px_32px_rgba(0,0,0,0.12)] backdrop-blur-sm"
            style={{
              boxShadow: '0 -2px 20px rgba(0,0,0,0.08), 0 8px 32px rgba(0,0,0,0.12)',
            }}
          >
            <div className="flex items-center justify-around px-2 h-16 relative">
              {/* Left items */}
              <div className="flex items-center">
                {navItems.slice(0, 2).map((item) => {
                  const isActive = activeTab === item.id;
                  const Icon = item.icon;

                  return (
                    <button
                      key={item.id}
                      onClick={() => handleTabChange(item.id)}
                      className={cn(
                        'flex flex-col items-center justify-center min-w-[52px] gap-0.5 py-2 px-3 rounded-2xl transition-all duration-300 relative',
                        isActive
                          ? 'bg-gray-900 text-white'
                          : 'text-gray-400 hover:text-gray-600'
                      )}
                    >
                      <div className="relative">
                        <Icon className={cn('w-5 h-5', isActive ? 'text-white' : 'text-gray-400')} />
                        {item.hasBadge && <OrdersBadge count={activeOrdersCount} />}
                      </div>
                      {isActive && (
                        <span className="text-[10px] font-semibold leading-none mt-0.5">
                          {item.label}
                        </span>
                      )}
                    </button>
                  );
                })}
              </div>

              {/* Center button - elevated */}
              <div className="flex items-center">
                {navItems.slice(2, 3).map((item) => {
                  const Icon = item.icon;

                  return (
                    <button
                      key={item.id}
                      onClick={() => handleTabChange(item.id)}
                      className="w-[52px] h-[52px] bg-gray-900 rounded-full flex items-center justify-center border-4 border-white shadow-lg hover:shadow-xl transition-all duration-300 -translate-y-2 hover:-translate-y-3 active:scale-95"
                    >
                      <Icon className="w-6 h-6 text-white" />
                    </button>
                  );
                })}
              </div>

              {/* Right items */}
              <div className="flex items-center">
                {navItems.slice(3, 4).map((item) => {
                  const isActive = activeTab === item.id;
                  const Icon = item.icon;

                  return (
                    <button
                      key={item.id}
                      onClick={() => handleTabChange(item.id)}
                      className={cn(
                        'flex flex-col items-center justify-center min-w-[52px] gap-0.5 py-2 px-3 rounded-2xl transition-all duration-300',
                        isActive
                          ? 'bg-gray-900 text-white'
                          : 'text-gray-400 hover:text-gray-600'
                      )}
                    >
                      <Icon className={cn('w-5 h-5', isActive ? 'text-white' : 'text-gray-400')} />
                      {isActive && (
                        <span className="text-[10px] font-semibold leading-none mt-0.5">
                          {item.label}
                        </span>
                      )}
                    </button>
                  );
                })}

                {/* More button */}
                <button
                  onClick={() => handleTabChange('more')}
                  className="flex flex-col items-center justify-center min-w-[52px] gap-0.5 py-2 px-3 rounded-2xl transition-all duration-300 text-gray-400 hover:text-gray-600"
                >
                  <MoreHorizontal className="w-5 h-5" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* More Bottom Sheet */}
      <MoreSheet
        isOpen={showMoreSheet}
        onClose={() => setShowMoreSheet(false)}
        onSelect={handleMoreSelect}
      />
    </>
  );
}
