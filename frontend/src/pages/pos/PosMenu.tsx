import { useEffect, useState } from 'react';
import { Search, Plus, Edit2, Trash2, Upload, Package, Eye, X, Grid, List } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getPosProducts, getPosCategories, createPosCategory, updatePosCategory, deletePosCategory, createPosProduct, updatePosProduct, deletePosProduct, getImageUrl } from '@/lib/hellomApi';

type Product = {
  id: number;
  name: string;
  description: string;
  price: number;
  image_path: string;
  is_available: boolean;
  track_stock: boolean;
  stock: number | null;
  category: { id: number; name: string };
  options?: Array<{
    name: string;
    type: 'single' | 'multi';
    is_required: boolean;
    values?: Array<{
      name: string;
      price_delta: number;
    }>;
  }>;
};

type Category = {
  id: number;
  name: string;
  is_active: boolean;
};

export default function PosMenu() {
  const [products, setProducts] = useState<Product[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingProduct, setEditingProduct] = useState<Product | null>(null);
  const [showCategoryModal, setShowCategoryModal] = useState(false);
  const [editingCategory, setEditingCategory] = useState<Category | null>(null);
  const [viewMode, setViewMode] = useState<'card' | 'list'>('card');
  const [formData, setFormData] = useState({
    category_id: '',
    name: '',
    description: '',
    price: '',
    is_available: true,
    track_stock: false,
    stock: '',
  });
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(null);
  const [productOptions, setProductOptions] = useState<Array<{
    name: string;
    type: 'single' | 'multi';
    is_required: boolean;
    values: Array<{
      name: string;
      price_delta: number;
    }>;
  }>>([]);
  const [categoryFormData, setCategoryFormData] = useState({
    name: '',
    is_active: true,
  });

  useEffect(() => {
    loadData();
  }, []);

  const loadData = async () => {
    try {
      setLoading(true);
      const [productsRes, categoriesRes] = await Promise.all([
        getPosProducts(),
        getPosCategories(),
      ]);
      setProducts(productsRes.products || []);
      setCategories(categoriesRes.categories || []);
    } catch (err) {
      setError('Failed to load menu data');
    } finally {
      setLoading(false);
    }
  };

  const filteredProducts = products.filter(product => {
    const matchesCategory = !selectedCategory || product.category?.id === selectedCategory;
    const matchesSearch = (product.name?.toLowerCase() || '').includes(searchTerm.toLowerCase()) ||
                         (product.description?.toLowerCase() || '').includes(searchTerm.toLowerCase());
    return matchesCategory && matchesSearch;
  });

  const isProductAvailable = (product: Product) =>
    product.is_available && (!product.track_stock || (product.stock ?? 0) > 0);

  const handleCreateProduct = () => {
    setEditingProduct(null);
    setShowCreateModal(true);
  };

  const handleEditProduct = (product: Product) => {
    setEditingProduct(product);
    // Populate form immediately when opening edit modal
    setFormData({
      category_id: String(product.category.id),
      name: product.name,
      description: product.description || '',
      price: String(product.price),
      is_available: product.is_available,
      track_stock: Boolean(product.track_stock),
      stock: product.stock === null || product.stock === undefined ? '' : String(product.stock),
    });
    // Set preview dari gambar existing
    setImagePreview(
      product.image_path
        ? getImageUrl(product.image_path)
        : null
    );
    setImageFile(null); // reset file baru

    // Populate options
    setProductOptions(
      product.options ? product.options.map(option => ({
        name: option.name,
        type: option.type,
        is_required: option.is_required,
        values: option.values ? option.values.map(value => ({
          name: value.name,
          price_delta: value.price_delta,
        })) : [],
      })) : []
    );

    setShowCreateModal(true);
  };

  const handleDeleteProduct = async (productId: number) => {
    if (confirm('Are you sure you want to delete this product?')) {
      try {
        await deletePosProduct(productId);
        await loadData();
      } catch (err) {
        alert('Failed to delete product');
      }
    }
  };

  const handleSubmitProduct = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      // Debug: log current state
      console.log('Current formData state:', formData);
      console.log('editingProduct:', editingProduct);

      // Debug: log form data being sent
      console.log('Submitting product form:', {
        category_id: formData.category_id,
        name: formData.name,
        description: formData.description,
        price: formData.price,
        is_available: formData.is_available,
        track_stock: formData.track_stock,
        stock: formData.stock,
        hasImage: !!imageFile,
        isEditing: !!editingProduct,
      });

      const data = new FormData();
      data.append('category_id', formData.category_id);
      data.append('name', formData.name);
      data.append('description', formData.description);
      data.append('price', formData.price);
      data.append('is_available', formData.is_available.toString());
      data.append('track_stock', formData.track_stock.toString());
      if (formData.track_stock) {
        data.append('stock', formData.stock || '0');
      }

      // Add options (filter out empty ones)
      const validOptions = productOptions.filter(option =>
        option.name.trim() && option.values.some(value => value.name.trim())
      );

      validOptions.forEach((option, optionIndex) => {
        data.append(`options[${optionIndex}][name]`, option.name.trim());
        data.append(`options[${optionIndex}][type]`, option.type);
        data.append(`options[${optionIndex}][is_required]`, option.is_required.toString());

        // Filter out empty values
        const validValues = option.values.filter(value => value.name.trim());
        validValues.forEach((value, valueIndex) => {
          data.append(`options[${optionIndex}][values][${valueIndex}][name]`, value.name.trim());
          data.append(`options[${optionIndex}][values][${valueIndex}][price_delta]`, value.price_delta.toString());
        });
      });

      if (imageFile) {
        data.append('image', imageFile);
      }

      // Add method spoofing for updates
      if (editingProduct) {
        data.append('_method', 'PATCH');
        console.log('Adding _method=PATCH for update');
      }

      // Debug: log FormData contents
      console.log('FormData entries:');
      for (const [key, value] of data.entries()) {
        console.log(`${key}:`, typeof value === 'string' ? `"${value}"` : value);
      }

      if (editingProduct) {
        await updatePosProduct(editingProduct.id, data);
      } else {
        await createPosProduct(data);
      }

      await loadData();
      setShowCreateModal(false);
      resetForm();
    } catch (err) {
      console.error('Error saving product:', err);
      alert(`Failed to save product: ${err instanceof Error ? err.message : 'Unknown error'}`);
    }
  };

  const resetForm = () => {
    setFormData({
      category_id: '',
      name: '',
      description: '',
      price: '',
      is_available: true,
      track_stock: false,
      stock: '',
    });
    setImageFile(null);
    setImagePreview(null);
    setProductOptions([]);
    setError(null);
    setSearchTerm('');
  };

  const handleSubmitCategory = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      if (editingCategory) {
        await updatePosCategory(editingCategory.id, categoryFormData);
      } else {
        await createPosCategory(categoryFormData);
      }

      await loadData();
      setShowCategoryModal(false);
      setEditingCategory(null);
      setCategoryFormData({ name: '', is_active: true });
    } catch (err) {
      alert('Failed to save category');
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-7xl mx-auto space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Manage Products</h1>
          <p className="text-gray-600 mt-1">Atur menu restoran dan kategori produknya ya</p>
        </div>
          <button
            onClick={handleCreateProduct}
            className="px-4 py-2 bg-amber-400 text-[#111111] rounded-lg hover:bg-amber-500 transition-colors flex items-center gap-2"
          >
            <Plus className="w-4 h-4" />
            Tambah Produk
          </button>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <p className="text-red-800">{error}</p>
        </div>
      )}

      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-4">
        <div className="flex-1 relative">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
          <input
            type="text"
            placeholder="Cari produk..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
          />
        </div>
        <select
          value={selectedCategory || ''}
          onChange={(e) => setSelectedCategory(e.target.value ? parseInt(e.target.value) : null)}
          className="px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-amber-300 focus:border-amber-300 text-gray-900 bg-white"
        >
          <option value="">Semua Kategori</option>
          {categories.map(category => (
            <option key={category.id} value={category.id}>{category.name}</option>
          ))}
        </select>

        {/* View Mode Toggle */}
        <div className="flex rounded-lg border border-gray-200 overflow-hidden">
          <button
            onClick={() => setViewMode('card')}
            className={cn(
              "px-3 py-2 text-sm font-medium transition-colors",
              viewMode === 'card'
                ? "bg-amber-400 text-[#111111]"
                : "bg-white text-gray-600 hover:bg-gray-50"
            )}
          >
            <Grid className="w-4 h-4 inline mr-1" />
            Card
          </button>
          <button
            onClick={() => setViewMode('list')}
            className={cn(
              "px-3 py-2 text-sm font-medium transition-colors",
              viewMode === 'list'
                ? "bg-amber-400 text-[#111111]"
                : "bg-white text-gray-600 hover:bg-gray-50"
            )}
          >
            <List className="w-4 h-4 inline mr-1" />
            List
          </button>
        </div>
      </div>

      {/* Categories Management */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Product Categories</h2>
        <div className="flex flex-wrap gap-2">
          {categories.map(category => (
            <span
              key={category.id}
              className={cn(
                "px-3 py-1 rounded-full text-sm border cursor-pointer",
                category.is_active
                  ? "bg-amber-100 text-amber-900 border-amber-200"
                  : "bg-gray-100 text-gray-600 border-gray-200"
              )}
              onClick={() => {
                setEditingCategory(category);
                setCategoryFormData({ name: category.name, is_active: category.is_active });
                setShowCategoryModal(true);
              }}
            >
              {category.name}
            </span>
          ))}
          <button
            onClick={() => {
              setEditingCategory(null);
              setCategoryFormData({ name: '', is_active: true });
              setShowCategoryModal(true);
            }}
            className="px-3 py-1 rounded-full text-sm border border-dashed border-gray-300 text-gray-500 hover:border-gray-400 hover:text-gray-700"
          >
            + Tambah Kategori
          </button>
        </div>
      </div>

      {/* Products Grid */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-8 text-center">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-amber-400 mx-auto mb-4"></div>
            <p className="text-gray-600">Loading menu...</p>
          </div>
        ) : viewMode === 'card' ? (
          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 md:gap-4 p-4 md:p-6">
            {filteredProducts.map(product => (
              <div key={product.id} className="border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition-shadow">
                <div className="aspect-square bg-gray-100 relative">
                  {product.image_path ? (
                    <img
                      src={getImageUrl(product.image_path)}
                      alt={product.name}
                      className="w-full h-full object-cover"
                      onError={(e) => { e.currentTarget.src = ''; }}
                    />
                  ) : (
                    <div className="w-full h-full flex items-center justify-center text-gray-400">
                      <Package className="w-8 h-8 md:w-10 md:h-10" />
                    </div>
                  )}
                  <div className="absolute top-1 right-1 flex gap-1">
                    <button
                      onClick={() => handleEditProduct(product)}
                      className="p-1 bg-white rounded-full shadow-sm hover:bg-gray-50"
                    >
                      <Edit2 className="w-3 h-3 md:w-4 md:h-4 text-gray-600" />
                    </button>
                    <button
                      onClick={() => handleDeleteProduct(product.id)}
                      className="p-1 bg-white rounded-full shadow-sm hover:bg-red-50"
                    >
                      <Trash2 className="w-3 h-3 md:w-4 md:h-4 text-red-600" />
                    </button>
                  </div>
                  {!isProductAvailable(product) && (
                    <div className="absolute bottom-1 left-1 px-1.5 py-0.5 md:px-2 md:py-1 bg-red-500 text-white text-xs rounded">
                      Habis
                    </div>
                  )}
                </div>
                <div className="p-2 md:p-3">
                  <div className="flex items-start justify-between mb-1 md:mb-2">
                    <div className="min-w-0 flex-1">
                      <h3 className="font-semibold text-gray-900 text-sm md:text-base truncate">{product.name}</h3>
                      <p className="text-xs md:text-sm text-gray-500 truncate">{product.category?.name}</p>
                    </div>
                    <span className="font-bold text-gray-900 text-sm md:text-base ml-1">
                      Rp {product.price.toLocaleString('id-ID')}
                    </span>
                  </div>
                  {product.description && (
                    <p className="text-xs md:text-sm text-gray-600 mb-2 line-clamp-1 md:line-clamp-2">
                      {product.description}
                    </p>
                  )}
                  <p className="text-xs text-gray-500 mb-2">
                    {product.track_stock
                      ? `Stok: ${product.stock ?? 0}`
                      : 'Stok: Unlimited'}
                  </p>
                  <button className="w-full px-2 py-1.5 md:px-3 md:py-2 text-xs md:text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition-colors flex items-center justify-center gap-1">
                    <Eye className="w-3 h-3 md:w-4 md:h-4" />
                    View
                  </button>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <div className="divide-y divide-gray-200">
            {filteredProducts.map(product => (
              <div key={product.id} className="flex items-center gap-3 p-3 md:p-4 hover:bg-gray-50">
                <div className="w-12 h-12 md:w-16 md:h-16 bg-gray-100 rounded-lg flex-shrink-0 overflow-hidden">
                  {product.image_path ? (
                    <img
                      src={getImageUrl(product.image_path)}
                      alt={product.name}
                      className="w-full h-full object-cover"
                      onError={(e) => { e.currentTarget.src = ''; }}
                    />
                  ) : (
                    <div className="w-full h-full flex items-center justify-center text-gray-400">
                      <Package className="w-5 h-5 md:w-6 md:h-6" />
                    </div>
                  )}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-start justify-between">
                    <div className="min-w-0 flex-1">
                      <h3 className="font-semibold text-gray-900 text-sm md:text-base truncate">{product.name}</h3>
                      <p className="text-xs md:text-sm text-gray-500 truncate">{product.category?.name}</p>
                      {product.description && (
                        <p className="text-xs md:text-sm text-gray-600 mt-1 line-clamp-1">
                          {product.description}
                        </p>
                      )}
                      <p className="text-xs text-gray-500 mt-1">
                        {product.track_stock
                          ? `Stok: ${product.stock ?? 0}`
                          : 'Stok: Unlimited'}
                      </p>
                    </div>
                    <div className="flex items-center gap-2 ml-3">
                      <span className="font-bold text-gray-900 text-sm md:text-base whitespace-nowrap">
                        Rp {product.price.toLocaleString('id-ID')}
                      </span>
                      {!isProductAvailable(product) && (
                        <span className="px-1.5 py-0.5 bg-red-500 text-white text-xs rounded whitespace-nowrap">
                          Habis
                        </span>
                      )}
                    </div>
                  </div>
                </div>
                <div className="flex gap-1 flex-shrink-0">
                  <button
                    onClick={() => handleEditProduct(product)}
                    className="p-1.5 md:p-2 text-gray-600 hover:text-amber-600 hover:bg-amber-50 rounded"
                    title="Edit"
                  >
                    <Edit2 className="w-4 h-4" />
                  </button>
                  <button
                    onClick={() => handleDeleteProduct(product.id)}
                    className="p-1.5 md:p-2 text-red-600 hover:text-red-700 hover:bg-red-50 rounded"
                    title="Delete"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {filteredProducts.length === 0 && !loading && (
        <div className="text-center py-8 md:py-12">
          <Package className="w-8 h-8 md:w-12 md:h-12 text-gray-400 mx-auto mb-4" />
          <p className="text-gray-500 text-sm md:text-base">No products found matching your criteria.</p>
        </div>
      )}

      {/* Create/Edit Product Modal */}
      {showCreateModal && (
        <div className="fixed inset-0 z-50 bg-white/20 backdrop-blur-md flex items-center justify-center p-4">
          <div className="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div className="p-6">
              <div className="flex items-center justify-between mb-6">
                  <h3 className="text-lg font-semibold text-gray-900">
                    {editingProduct ? 'Edit Produk' : 'Tambah Produk Baru'}
                  </h3>
                <button
                  onClick={() => {
                    setShowCreateModal(false);
                    resetForm();
                  }}
                  className="text-gray-400 hover:text-gray-600"
                >
                  <X className="w-6 h-6" />
                </button>
              </div>

              <form onSubmit={handleSubmitProduct} className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-800 mb-1">
                      Pilih Kategori *
                    </label>
                    <select
                      value={formData.category_id}
                      onChange={(e) => setFormData({ ...formData, category_id: e.target.value })}
                      className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                      required
                    >
                      <option value="">Pilih kategori dulu ya</option>
                      {categories.map(category => (
                        <option key={category.id} value={category.id}>{category.name}</option>
                      ))}
                    </select>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-800 mb-1">
                      Nama Produk *
                    </label>
                    <input
                      type="text"
                      value={formData.name}
                      onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                      placeholder="misalnya: Nasi Goreng Spesial"
                      className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                      required
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-800 mb-1">
                    Deskripsi (kalo mau aja)
                  </label>
                  <textarea
                    value={formData.description}
                    onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                    placeholder="ceritain sedikit tentang produk ini ya..."
                    rows={3}
                    className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                  />
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-800 mb-1">
                      Harga (Rp) *
                    </label>
                    <input
                      type="number"
                      value={formData.price}
                      onChange={(e) => setFormData({ ...formData, price: e.target.value })}
                      placeholder="0"
                      className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                      min="0"
                      required
                    />
                  </div>

                  <div className="flex items-center">
                    <input
                      type="checkbox"
                      id="is_available"
                      checked={formData.is_available}
                      onChange={(e) => setFormData({ ...formData, is_available: e.target.checked })}
                      className="h-4 w-4 text-amber-500 focus:ring-amber-300 border-gray-300 rounded"
                    />
                    <label htmlFor="is_available" className="ml-2 text-sm text-gray-700">
                      Produk lagi ready dijual
                    </label>
                  </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="flex items-center">
                    <input
                      type="checkbox"
                      id="track_stock"
                      checked={formData.track_stock}
                      onChange={(e) => setFormData({
                        ...formData,
                        track_stock: e.target.checked,
                        stock: e.target.checked ? formData.stock : '',
                      })}
                      className="h-4 w-4 text-amber-500 focus:ring-amber-300 border-gray-300 rounded"
                    />
                    <label htmlFor="track_stock" className="ml-2 text-sm text-gray-700">
                      Gunakan stok (batasi jumlah)
                    </label>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-800 mb-1">
                      Jumlah Stok
                    </label>
                    <input
                      type="number"
                      value={formData.stock}
                      onChange={(e) => setFormData({ ...formData, stock: e.target.value })}
                      placeholder={formData.track_stock ? '0' : 'Unlimited'}
                      className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                      min="0"
                      disabled={!formData.track_stock}
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-800 mb-1">
                    Foto Produk (kalo ada)
                  </label>
                  <div className="border-2 border-dashed border-gray-300 rounded-lg p-6 bg-gray-50 hover:bg-gray-100 transition-colors cursor-pointer">
                    <input
                      type="file"
                      accept="image/*"
                      onChange={(e) => {
                        const file = e.target.files?.[0];
                        if (file) {
                          setImageFile(file);
                          setImagePreview(URL.createObjectURL(file)); // preview lokal
                        }
                      }}
                      className="hidden"
                      id="image-upload"
                    />
                    <label htmlFor="image-upload" className="cursor-pointer">
                      {imagePreview ? (
                        <div className="space-y-3">
                          <img
                            src={imagePreview}
                            alt="Preview"
                            className="w-full h-32 object-cover rounded-lg"
                            onError={(e) => { e.currentTarget.style.display = 'none'; }}
                          />
                          <div className="flex items-center justify-between">
                            <span className="text-sm text-gray-600">{imageFile?.name}</span>
                            <button
                              type="button"
                              onClick={(e) => {
                                e.preventDefault();
                                setImageFile(null);
                                setImagePreview(null);
                              }}
                              className="text-red-500 hover:text-red-700 p-1"
                            >
                              <X className="w-4 h-4" />
                            </button>
                          </div>
                        </div>
                      ) : (
                        <div className="text-center space-y-2">
                          <Upload className="w-10 h-10 text-gray-400 mx-auto" />
                          <div>
                            <p className="text-sm font-medium text-gray-700">Klik untuk upload foto produk</p>
                            <p className="text-sm text-gray-400">atau seret & lepas di sini</p>
                            <p className="text-xs text-gray-400 mt-1">PNG, JPG, WEBP maks. 2MB</p>
                          </div>
                        </div>
                      )}
                    </label>
                  </div>
                </div>

                {/* Product Options */}
                <div className="border-t border-gray-200 pt-4">
                  <div className="flex items-center justify-between mb-4">
                    <h4 className="text-md font-medium text-gray-900">Opsi Tambahan (Add-on)</h4>
                    <button
                      type="button"
                      onClick={() => setProductOptions([...productOptions, {
                        name: '',
                        type: 'single',
                        is_required: false,
                        values: [{ name: '', price_delta: 0 }]
                      }])}
                      className="px-3 py-1 bg-[#111111] text-white text-sm rounded hover:bg-[#2a241d] transition-colors"
                    >
                      + Tambah Opsi
                    </button>
                  </div>

                  <div className="space-y-4">
                    {productOptions.map((option, optionIndex) => (
                      <div key={optionIndex} className="border border-gray-200 rounded-lg p-4">
                        <div className="flex items-center justify-between mb-3">
                          <div className="flex items-center gap-3">
                            <input
                              type="text"
                              placeholder="misalnya: Tingkat Kepedasan"
                              value={option.name}
                              onChange={(e) => {
                                const newOptions = [...productOptions];
                                newOptions[optionIndex].name = e.target.value;
                                setProductOptions(newOptions);
                              }}
                              className="px-3 py-2 border border-gray-300 rounded text-sm text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                            />
                            <select
                              value={option.type}
                              onChange={(e) => {
                                const newOptions = [...productOptions];
                                newOptions[optionIndex].type = e.target.value as 'single' | 'multi';
                                setProductOptions(newOptions);
                              }}
                              className="px-3 py-2 border border-gray-300 rounded text-sm text-gray-900 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                            >
                              <option value="single">Pilih satu aja</option>
                              <option value="multi">Boleh pilih banyak</option>
                            </select>
                            <label className="flex items-center gap-2 text-sm text-gray-800">
                              <input
                                type="checkbox"
                                checked={option.is_required}
                                onChange={(e) => {
                                  const newOptions = [...productOptions];
                                  newOptions[optionIndex].is_required = e.target.checked;
                                  setProductOptions(newOptions);
                                }}
                                className="h-4 w-4 text-amber-500 focus:ring-amber-300 border-gray-300 rounded"
                              />
                              Harus dipilih
                            </label>
                          </div>
                          <button
                            type="button"
                            onClick={() => {
                              const newOptions = productOptions.filter((_, i) => i !== optionIndex);
                              setProductOptions(newOptions);
                            }}
                            className="text-red-600 hover:text-red-800 p-1"
                          >
                            <X className="w-5 h-5" />
                          </button>
                        </div>

                        <div className="space-y-2">
                          <div className="flex items-center justify-between">
                            <span className="text-sm font-medium text-gray-800">Pilihan:</span>
                            <button
                              type="button"
                              onClick={() => {
                                const newOptions = [...productOptions];
                                newOptions[optionIndex].values.push({ name: '', price_delta: 0 });
                                setProductOptions(newOptions);
                              }}
                              className="px-2 py-1 bg-amber-100 text-amber-900 text-xs rounded hover:bg-amber-200"
                            >
                              + Tambah Pilihan
                            </button>
                          </div>

                          {option.values.map((value, valueIndex) => (
                            <div key={valueIndex} className="flex items-center gap-2">
                              <input
                                type="text"
                                placeholder="misalnya: Pedas Banget"
                                value={value.name}
                                onChange={(e) => {
                                  const newOptions = [...productOptions];
                                  newOptions[optionIndex].values[valueIndex].name = e.target.value;
                                  setProductOptions(newOptions);
                                }}
                                className="flex-1 px-3 py-2 border border-gray-300 rounded text-sm text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                              />
                              <div className="flex items-center gap-1">
                                <span className="text-sm text-gray-600">+Rp</span>
                                <input
                                  type="number"
                                  placeholder="0"
                                  value={value.price_delta}
                                  onChange={(e) => {
                                    const newOptions = [...productOptions];
                                    newOptions[optionIndex].values[valueIndex].price_delta = parseInt(e.target.value) || 0;
                                    setProductOptions(newOptions);
                                  }}
                                  className="w-20 px-3 py-2 border border-gray-300 rounded text-sm text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                                  min="0"
                                />
                              </div>
                              {option.values.length > 1 && (
                                <button
                                  type="button"
                                  onClick={() => {
                                    const newOptions = [...productOptions];
                                    newOptions[optionIndex].values = newOptions[optionIndex].values.filter((_, i) => i !== valueIndex);
                                    setProductOptions(newOptions);
                                  }}
                                  className="text-red-600 hover:text-red-800 p-1"
                                >
                                  <X className="w-4 h-4" />
                                </button>
                              )}
                            </div>
                          ))}
                        </div>
                      </div>
                    ))}
                  </div>

                  {productOptions.length === 0 && (
                    <p className="text-sm text-gray-500 text-center py-4">
                      Belum ada opsi tambahan. Klik "Tambah Opsi" untuk buat pilihan custom produk ini.
                    </p>
                  )}
                </div>

                <div className="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    onClick={() => {
                      setShowCreateModal(false);
                      resetForm();
                    }}
                    className="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 bg-amber-400 text-[#111111] hover:bg-amber-500 rounded-lg transition-colors"
                  >
                    {editingProduct ? 'Update Produk' : 'Simpan Produk'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      )}

      {/* Category Modal */}
      {showCategoryModal && (
        <div className="fixed inset-0 bg-white/20 backdrop-blur-md flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl max-w-md w-full p-6">
            <div className="flex items-center justify-between mb-6">
              <h3 className="text-lg font-semibold text-gray-900">
                {editingCategory ? 'Edit Kategori' : 'Tambah Kategori Baru'}
              </h3>
              <button
                onClick={() => setShowCategoryModal(false)}
                className="text-gray-400 hover:text-gray-600"
              >
                <X className="w-6 h-6" />
              </button>
            </div>

            <form onSubmit={handleSubmitCategory} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Nama Kategori *
                </label>
                <input
                  type="text"
                  value={categoryFormData.name}
                  onChange={(e) => setCategoryFormData({ ...categoryFormData, name: e.target.value })}
                  className="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-amber-300 focus:border-amber-300"
                  required
                />
              </div>

              <div className="flex items-center">
                <input
                  type="checkbox"
                  id="category_active"
                  checked={categoryFormData.is_active}
                  onChange={(e) => setCategoryFormData({ ...categoryFormData, is_active: e.target.checked })}
                  className="h-4 w-4 text-amber-500 focus:ring-amber-300 border-gray-300 rounded"
                />
                <label htmlFor="category_active" className="ml-2 text-sm text-gray-700">
                  Kategori aktif
                </label>
              </div>

              <div className="flex justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => setShowCategoryModal(false)}
                  className="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-amber-400 text-[#111111] hover:bg-amber-500 rounded-lg transition-colors"
                >
                  {editingCategory ? 'Update Kategori' : 'Simpan Kategori'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
      </div>
    </div>
  );
}
