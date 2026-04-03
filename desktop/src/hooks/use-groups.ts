import { useQuery } from '@tanstack/react-query';
import { http } from '@/lib/http';
import type { GroupsResponse } from '@/types';

export function useGroups(keyword?: string) {
  return useQuery({
    queryKey: ['groups', keyword],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (keyword) params.set('keyword', keyword);
      params.set('per_page', '100');
      return http.get<GroupsResponse['data']>(
        `desktop_groups.php?${params.toString()}`
      );
    },
  });
}

export function useGroupResources(
  groupCode: string,
  assetType: 'works' | 'models' | 'customer'
) {
  return useQuery({
    queryKey: ['group-resources', groupCode, assetType],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.set('group_code', groupCode);
      params.set('asset_type', assetType);
      params.set('per_page', '500');
      return http.get<{ items: unknown[]; total: number }>(
        `desktop_group_resources.php?${params.toString()}`
      );
    },
    enabled: !!groupCode,
  });
}
